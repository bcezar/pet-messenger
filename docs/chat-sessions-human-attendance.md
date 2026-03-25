# Chat Sessions - Human Attendance & Inbox Panel

## Visão Geral

Evolução da tabela `chat_sessions` para suportar atendimento humano e painel de inbox estilo CRM, mantendo compatibilidade com o atendimento bot existente.

## Novos Campos

### Campos de Rastreamento de Mensagens

#### `last_message_at` (timestamp nullable)
- **Propósito**: Timestamp da última mensagem recebida ou enviada
- **Uso**: Ordenação de sessões no inbox (mais recentes primeiro)
- **Atualização**: Automática quando nova mensagem é criada
- **Indexado**: Sim (parte do `inbox_listing_index`)

#### `last_message_direction` (string 20 nullable)
- **Propósito**: Direção da última mensagem (inbound/outbound)
- **Uso**: UI pode mostrar indicadores visuais diferentes
- **Valores**: `inbound` | `outbound`
- **Não usa ENUM**: Mantém flexibilidade para mudanças futuras

#### `unread_count` (integer default 0)
- **Propósito**: Contador de mensagens não lidas pelo atendente
- **Uso**: Badge de notificação no inbox
- **Comportamento**:
  - Incrementa quando mensagem inbound chega
  - Zera quando atendente visualiza a sessão
- **Otimização**: Evita COUNT queries pesadas

### Campos de Status e Controle

#### `status` (string 20 default 'bot')
- **Propósito**: Estado atual do atendimento
- **Valores**:
  - `bot`: Atendimento automatizado ativo
  - `human`: Transferido para atendimento humano
  - `closed`: Sessão encerrada
- **Não usa ENUM**: Permite adicionar novos status sem ALTER TABLE
- **Indexado**: Sim (parte do `inbox_listing_index` e `user_sessions_index`)

#### `locked_by_user_id` (foreignId nullable)
- **Propósito**: ID do usuário que travou a sessão
- **Uso**: Previne múltiplos atendentes na mesma sessão
- **Comportamento**:
  - Setado quando usuário "pega" a sessão
  - NULL quando sessão está disponível
  - CASCADE null on delete do usuário
- **Indexado**: Sim (parte do `user_sessions_index`)

#### `locked_at` (timestamp nullable)
- **Propósito**: Quando a sessão foi travada
- **Uso**: 
  - Auditoria de atendimento
  - Detectar sessões "esquecidas" (timeout)
  - Métricas de tempo de resposta

## Decisões Arquiteturais

### 1. Não Usar ENUM

**Decisão**: Usar VARCHAR(20) ao invés de ENUM para status e direction.

**Razão**:
- ENUMs em MySQL exigem ALTER TABLE para adicionar valores
- Dificulta migrations e deploys zero-downtime
- Menos flexível para evolução do sistema
- Constants no Model oferecem mesma segurança de tipo

```php
// No Model mantemos type safety
const STATUS_BOT = 'bot';
const STATUS_HUMAN = 'human';
const STATUS_CLOSED = 'closed';
```

### 2. Multi-Tenant Obrigatório

**Decisão**: Todos os scopes e queries devem filtrar por `company_id`.

**Implementação**:
```php
// Sempre usar scope forCompany
ChatSession::forCompany($companyId)
    ->human()
    ->withUnread()
    ->forInbox()
    ->get();
```

**Segurança**: Previne vazamento de dados entre empresas.

### 3. Índices Compostos Otimizados

#### `inbox_listing_index` (company_id, status, last_message_at)

**Query típica do inbox**:
```sql
SELECT * FROM chat_sessions
WHERE company_id = ?
AND status IN ('bot', 'human')
ORDER BY last_message_at DESC
LIMIT 50
```

**Benefício**: Query usa apenas o índice (index-only scan quando possível).

#### `user_sessions_index` (locked_by_user_id, status)

**Query típica "minhas sessões"**:
```sql
SELECT * FROM chat_sessions
WHERE locked_by_user_id = ?
AND status = 'human'
```

**Benefício**: Lookup rápido para dashboard do atendente.

### 4. Desnormalização Controlada

**Decisão**: Armazenar `last_message_at` e `unread_count` na sessão.

**Trade-off**:
- ✅ Queries de listagem muito mais rápidas
- ✅ Sem JOINs ou subqueries complexas
- ❌ Precisa manter sincronizado com tabela messages

**Mitigação**: Usar Model Observers ou eventos para manter consistência.

### 5. Lock Soft (Não Pessimista)

**Decisão**: Lock baseado em coluna, não em lock de linha do DB.

**Razão**:
- Mais simples de implementar
- Não trava conexões do DB
- Permite timeout e liberação automática
- UI pode mostrar "Atendido por João" mesmo se outro visualizar

**Limitação**: Race condition se dois atendentes clicarem simultaneamente (aceitável para o caso de uso).

## Relacionamentos

### ChatSession -> User (lockedBy)
```php
// ChatSession.php
public function lockedBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'locked_by_user_id');
}
```

### User -> ChatSession (lockedSessions)
```php
// User.php
public function lockedSessions(): HasMany
{
    return $this->hasMany(ChatSession::class, 'locked_by_user_id');
}
```

## Scopes Principais

### Para Listagem de Inbox

```php
// Todas sessões do inbox ordenadas
ChatSession::forCompany($companyId)
    ->active()
    ->forInbox()
    ->get();

// Apenas com não lidas
ChatSession::forCompany($companyId)
    ->human()
    ->withUnread()
    ->forInbox()
    ->get();
```

### Para Dashboard do Atendente

```php
// Minhas sessões ativas
ChatSession::forCompany($companyId)
    ->lockedByUser($userId)
    ->human()
    ->get();

// Sessões disponíveis (não travadas ou travadas por mim)
ChatSession::forCompany($companyId)
    ->human()
    ->availableFor($userId)
    ->get();
```

### Por Status

```php
// Atendimento bot
ChatSession::forCompany($companyId)->bot()->get();

// Atendimento humano
ChatSession::forCompany($companyId)->human()->get();

// Fechadas
ChatSession::forCompany($companyId)->closed()->get();

// Ativas (bot ou human)
ChatSession::forCompany($companyId)->active()->get();
```

## Métodos Auxiliares

### Gerenciamento de Lock

```php
// Travar para atendente
$session->lockForUser($userId);

// Destravar
$session->unlock();

// Verificar
$session->isLocked(); // bool
$session->isLockedBy($userId); // bool
```

### Transição de Status

```php
// Transferir para humano
$session->transferToHuman();

// Retornar para bot
$session->transferToBot();

// Fechar sessão
$session->close();
```

### Atualização de Mensagens

```php
// Nova mensagem recebida
$session->updateLastMessage('inbound');
$session->incrementUnread();

// Atendente visualizou
$session->markAsRead();
```

## Exemplo de Implementação: Inbox Controller

```php
class InboxController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;
        
        $sessions = ChatSession::forCompany($companyId)
            ->when($request->status, fn($q, $status) => $q->byStatus($status))
            ->when($request->unread_only, fn($q) => $q->withUnread())
            ->forInbox()
            ->paginate(50);
        
        return Inertia::render('Inbox/Index', [
            'sessions' => $sessions,
            'stats' => [
                'total' => ChatSession::forCompany($companyId)->active()->count(),
                'human' => ChatSession::forCompany($companyId)->human()->count(),
                'unread' => ChatSession::forCompany($companyId)->withUnread()->count(),
            ],
        ]);
    }
    
    public function lock(ChatSession $session)
    {
        $this->authorize('lock', $session);
        
        if ($session->isLocked() && !$session->isLockedBy(auth()->id())) {
            return response()->json([
                'error' => 'Sessão já está sendo atendida por ' . $session->lockedBy->name
            ], 409);
        }
        
        $session->lockForUser(auth()->id());
        $session->markAsRead();
        
        return response()->json(['success' => true]);
    }
}
```

## Exemplo de Implementação: Message Observer

```php
class MessageObserver
{
    public function created(Message $message)
    {
        $session = $message->chatSession;
        
        // Atualizar timestamp e direção
        $session->updateLastMessage($message->direction);
        
        // Incrementar não lidas se for mensagem do cliente
        if ($message->isInbound() && $message->isFromClient()) {
            $session->incrementUnread();
        }
        
        // Auto-transferir para humano se bot não conseguiu responder
        if ($this->shouldTransferToHuman($message)) {
            $session->transferToHuman();
        }
    }
    
    private function shouldTransferToHuman(Message $message): bool
    {
        // Lógica para detectar quando transferir
        // Ex: cliente pede "falar com atendente"
        return str_contains(
            strtolower($message->content),
            'atendente'
        );
    }
}
```

## Próximos Passos

### Implementação Backend
1. ✅ Migration criada
2. ✅ Model atualizado com scopes
3. ⏳ Criar InboxController
4. ⏳ Implementar MessageObserver
5. ⏳ Adicionar testes

### Implementação Frontend
1. ⏳ Criar página Inbox/Index
2. ⏳ Implementar lista de sessões
3. ⏳ Chat view com lock/unlock
4. ⏳ Real-time updates (Pusher/Echo)
5. ⏳ Notificações de novas mensagens

### Melhorias Futuras
- Timeout automático de sessões travadas (ex: 30min)
- Métricas de atendimento (tempo médio, satisfação)
- Sistema de categorização de sessões
- Templates de respostas rápidas
- Histórico de atendimentos

## Compatibilidade

### Com Sistema Existente
- ✅ `client_phone` mantido (renomear em migration futura se necessário)
- ✅ `state` mantido (pode coexistir com `status`)
- ✅ `data` mantido (contexto do bot)
- ✅ Multi-tenant respeitado
- ✅ Relacionamento com messages preservado

### Migration Segura
- Todos campos nullable ou com default
- Não quebra queries existentes
- Pode rodar em produção sem downtime
- Rollback completo implementado
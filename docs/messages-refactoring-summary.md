# Refatoração da Tabela Messages - Resumo das Mudanças

## 1. Migration Refatorada (`2025_05_20_000000_create_messages_table.php`)

### Mudanças Estruturais:

#### ✅ Substituição de ENUMs por STRING(20)
- `direction`: string(20) - valores: 'inbound', 'outbound'
- `sender_type`: string(20) - valores: 'client', 'bot', 'human'
- `status`: string(20) - valores: 'sent', 'delivered', 'read', 'failed'
- `channel`: string(20) - valor padrão: 'whatsapp'

**Razão**: Compatibilidade total com SQLite e maior flexibilidade futura.

#### ✅ Novo Campo `client_phone`
- Tipo: `string(20)` com índice
- Indexado para buscas rápidas por telefone do cliente
- Essencial para queries multi-tenant por cliente

#### ✅ Constraint UNIQUE em `external_id + channel`
```php
$table->unique(['external_id', 'channel'], 'messages_external_id_channel_unique');
```
- Previne duplicação de mensagens de webhooks
- Suporta múltiplos canais (WhatsApp, SMS, etc)

#### ✅ Foreign Keys com `restrictOnDelete()`
- Substituiu `onDelete('cascade')` por `restrictOnDelete()`
- Mais seguro: previne deleção acidental em cascata
- Força decisão explícita sobre como tratar registros órfãos

```php
$table->foreignId('company_id')
    ->constrained('companies')
    ->restrictOnDelete();

$table->foreignId('chat_session_id')
    ->nullable()
    ->constrained('chat_sessions')
    ->restrictOnDelete();

$table->foreignId('user_id')
    ->nullable()
    ->constrained('users')
    ->restrictOnDelete();
```

#### ✅ Índices Otimizados (apenas essenciais)
```php
// Índice para busca por telefone do cliente
$table->string('client_phone', 20)->index();

// Índice composto para queries de sessão
$table->index(['company_id', 'chat_session_id']);

// Índice composto para histórico de cliente
$table->index(['company_id', 'client_phone', 'created_at']);

// Índice único para prevenir duplicatas
$table->unique(['external_id', 'channel']);
```

**Redução**: De 7+ índices para apenas 4 índices essenciais.

### Campos Removidos (simplificação):
- ❌ `content_type` (enum)
- ❌ `sent_at`, `delivered_at`, `read_at`, `failed_at` (timestamps separados)
- ❌ `media_url`, `media_type`, `media_size` (campos de mídia)
- ❌ `error_code` (mantido apenas `error_message`)

**Razão**: Evitar overengineering. Esses campos podem ser adicionados quando realmente necessários.

### Campos Mantidos/Adicionados:
- ✅ `client_name`: string(100), nullable - nome do cliente
- ✅ `metadata`: json, nullable - dados flexíveis (erros, contexto, etc)
- ✅ `error_message`: text, nullable - mensagens de erro

---

## 2. Model Message Refatorado

### Constantes para Valores Permitidos

```php
// Direction constants
public const DIRECTION_INBOUND = 'inbound';
public const DIRECTION_OUTBOUND = 'outbound';

// Sender type constants
public const SENDER_CLIENT = 'client';
public const SENDER_BOT = 'bot';
public const SENDER_HUMAN = 'human';

// Status constants
public const STATUS_SENT = 'sent';
public const STATUS_DELIVERED = 'delivered';
public const STATUS_READ = 'read';
public const STATUS_FAILED = 'failed';

// Channel constants
public const CHANNEL_WHATSAPP = 'whatsapp';
```

**Benefícios**:
- Valores controlados via código
- Autocomplete na IDE
- Fácil refatoração
- Documentação implícita

### Scopes Úteis

```php
Message::inbound()        // WHERE direction = 'inbound'
Message::outbound()       // WHERE direction = 'outbound'
Message::fromClient()     // WHERE sender_type = 'client'
Message::fromBot()        // WHERE sender_type = 'bot'
Message::fromHuman()      // WHERE sender_type = 'human'
Message::failed()         // WHERE status = 'failed'
```

**Uso**:
```php
// Mensagens de clientes que falharam
Message::fromClient()->failed()->get();

// Mensagens de saída do bot
Message::outbound()->fromBot()->latest()->get();
```

### Métodos Auxiliares

```php
$message->isFromClient()  // bool
$message->isFromBot()     // bool
$message->isFromHuman()   // bool
$message->isInbound()     // bool
$message->isOutbound()    // bool
$message->isFailed()      // bool

$message->markAsDelivered()  // bool
$message->markAsRead()       // bool
$message->markAsFailed($errorMessage)  // bool
```

---

## 3. Relacionamentos Atualizados

### Company Model
```php
public function messages()
{
    return $this->hasMany(Message::class);
}
```

### ChatSession Model
```php
public function messages()
{
    return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
}

public function latestMessage()
{
    return $this->hasOne(Message::class)->latestOfMany();
}
```

### User Model
```php
public function messages(): HasMany
{
    return $this->hasMany(Message::class);
}
```

---

## 4. Compatibilidade SQLite

### ✅ Mudanças Garantindo Compatibilidade:

1. **String ao invés de ENUM**: SQLite não suporta ENUM nativamente
2. **Índices simples**: SQLite tem limitações em índices complexos
3. **Foreign keys com restrict**: Funciona corretamente no SQLite
4. **JSON nativo**: Suportado desde SQLite 3.9.0+

### Testando a Migration:

```bash
# Resetar banco e rodar migrations
php artisan migrate:fresh

# Verificar estrutura criada
php artisan migrate:status

# Criar mensagem de teste
php artisan tinker
>>> $company = Company::first();
>>> Message::create([
    'company_id' => $company->id,
    'content' => 'Teste',
    'direction' => Message::DIRECTION_INBOUND,
    'sender_type' => Message::SENDER_CLIENT,
    'client_phone' => '+5511999999999',
]);
```

---

## 5. Preparação para Produção

### MySQL/PostgreSQL:
- Estrutura compatível com ambos
- Índices adequados para queries multi-tenant
- JSON nativo suportado

### Performance:
- Índices essenciais cobrem queries principais:
  - Busca por sessão: `(company_id, chat_session_id)`
  - Histórico do cliente: `(company_id, client_phone, created_at)`
  - Lookup de webhook: `(external_id, channel)`

### Escalabilidade:
- Estrutura preparada para alto volume
- Índices não excessivos (evita overhead em INSERTs)
- Campos flexíveis (metadata JSON) para evolução futura

---

## 6. Próximos Passos Recomendados

1. **Validação**: Adicionar FormRequest para validar inputs
2. **Observer**: Criar MessageObserver para logs/auditoria
3. **Queue**: Processar envio de mensagens em background
4. **Testes**: Criar testes unitários e de integração
5. **Monitoring**: Adicionar tracking de mensagens falhadas

---

## Resumo da Refatoração

| Aspecto | Antes | Depois |
|---------|-------|--------|
| Tipos de campo | ENUMs | STRING(20) |
| Controle de valores | Banco de dados | Constantes no Model |
| Índices | 7+ índices | 4 índices essenciais |
| Foreign keys | CASCADE | RESTRICT |
| Compatibilidade SQLite | Parcial | ✅ Total |
| Campos de mídia | Presentes | Removidos (YAGNI) |
| Timestamps específicos | 4 campos | Removidos (usar metadata) |
| Preparação futura | Over-engineered | Pragmática |

**Filosofia**: Clean, simples, preparado para produção, evitando overengineering.
# Messages Table Design - Decisões de Modelagem

## Visão Geral

A tabela `messages` foi projetada para ser o núcleo do sistema de armazenamento de mensagens, suportando:
- Histórico completo de conversas
- Painel de atendimento humano
- Auditoria de mensagens
- Alto volume de operações
- Escalabilidade futura para múltiplos canais

## Estrutura de Campos

### 1. **Relacionamentos Multi-Tenant**

```php
$table->foreignId('company_id')->constrained()->onDelete('cascade');
$table->foreignId('chat_session_id')->constrained()->onDelete('cascade');
```

**Decisão**: Todo message está vinculado a uma company e a uma chat_session, garantindo isolamento de dados e rastreabilidade completa.

**Benefício**: Suporta arquitetura multi-tenant com segurança e permite consultas eficientes por empresa/sessão.

---

### 2. **Identificação Externa e Canal**

```php
$table->string('external_id')->nullable();
$table->string('channel')->default('whatsapp');
```

**Decisão**: 
- `external_id`: Armazena o ID fornecido pelo provedor externo (WhatsApp Message ID, SMS ID, etc)
- `channel`: Permite extensão futura para SMS, Telegram, outros canais

**Benefício**: 
- Evita duplicação de mensagens
- Facilita reconciliação com webhooks de status
- Prepara sistema para omnichannel

---

### 3. **Direção e Tipo de Remetente**

```php
$table->enum('direction', ['inbound', 'outbound'])->index();
$table->enum('sender_type', ['client', 'bot', 'human'])->index();
```

**Decisão**: Separar **quem enviou** (sender_type) de **para onde vai** (direction).

**Cenários possíveis**:
- `direction: inbound, sender_type: client` → Cliente enviou mensagem
- `direction: outbound, sender_type: bot` → Bot respondeu
- `direction: outbound, sender_type: human` → Atendente humano enviou

**Benefício**: Flexibilidade para análises e filtros complexos (ex: "todas as respostas do bot", "mensagens do cliente não respondidas").

---

### 4. **Conteúdo e Tipo**

```php
$table->text('content')->nullable();
$table->enum('content_type', ['text', 'image', 'video', 'audio', 'document', 'location', 'contact', 'sticker'])->default('text');
```

**Decisão**: `content` como TEXT (não VARCHAR) para suportar mensagens longas. `content_type` preparado para diversos formatos do WhatsApp.

**Benefício**: Suporta naturalmente todas as funcionalidades do WhatsApp Business API.

---

### 5. **Rastreamento de Entrega**

```php
$table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending')->index();
$table->timestamp('sent_at')->nullable();
$table->timestamp('delivered_at')->nullable();
$table->timestamp('read_at')->nullable();
$table->timestamp('failed_at')->nullable();
```

**Decisão**: Status completo do ciclo de vida da mensagem + timestamps específicos.

**Benefício**: 
- Auditoria completa
- SLA de resposta
- Métricas de engajamento (tempo até leitura)
- Retry de mensagens falhadas

---

### 6. **Mídia e Anexos**

```php
$table->string('media_url')->nullable();
$table->string('media_type')->nullable();
$table->unsignedInteger('media_size')->nullable();
```

**Decisão**: Campos preparados para armazenar referências a arquivos de mídia.

**Implementação futura**: 
- `media_url` pode apontar para S3/storage local
- `media_type` armazena MIME type
- `media_size` útil para validações e relatórios

---

### 7. **Metadata (JSON)**

```php
$table->json('metadata')->nullable();
```

**Decisão**: Campo flexível para dados específicos do provedor.

**Casos de uso**:
```json
{
  "whatsapp_message_id": "wamid.xxx",
  "whatsapp_timestamp": 1234567890,
  "context": {
    "from": "+5511999999999",
    "profile_name": "João Silva"
  },
  "button_response": {
    "button_id": "btn_1",
    "button_text": "Sim"
  }
}
```

**Benefício**: Extensibilidade sem alterar schema.

---

### 8. **Tracking de Agente Humano**

```php
$table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
```

**Decisão**: Vincular mensagens enviadas por humanos ao usuário do sistema.

**Benefício**: 
- Auditoria de quem atendeu
- Performance de atendentes
- Histórico de interações

---

### 9. **Rastreamento de Erros**

```php
$table->string('error_code')->nullable();
$table->text('error_message')->nullable();
```

**Decisão**: Capturar erros detalhados de envio.

**Benefício**: Debug, monitoring, alertas automáticos de falhas.

---

## Índices para Performance

### Índices Compostos Estratégicos

```php
// Histórico de conversas (query mais comum)
$table->index(['company_id', 'chat_session_id', 'created_at'], 'messages_session_timeline');

// Timeline de mensagens por empresa
$table->index(['company_id', 'created_at'], 'messages_company_timeline');

// Timeline de sessão específica
$table->index(['chat_session_id', 'created_at'], 'messages_chat_timeline');

// Rastreamento de status
$table->index(['company_id', 'status', 'created_at'], 'messages_status_tracking');

// Analytics por tipo de remetente
$table->index(['company_id', 'sender_type', 'created_at'], 'messages_sender_analytics');

// Lookup de webhooks
$table->index(['external_id', 'channel'], 'messages_external_lookup');

// Monitoramento de falhas
$table->index(['status', 'failed_at'], 'messages_failed_monitoring');
```

### Justificativa dos Índices

1. **messages_session_timeline**: Query principal do histórico de conversa
2. **messages_company_timeline**: Dashboard/relatórios por empresa
3. **messages_status_tracking**: Monitoramento de entregas e falhas
4. **messages_sender_analytics**: Métricas bot vs human vs client
5. **messages_external_lookup**: Reconciliação rápida com webhooks do WhatsApp
6. **messages_failed_monitoring**: Alertas e retry de mensagens falhadas

---

## Model e Relacionamentos

### Message Model

```php
// Relacionamentos
$message->company()      // BelongsTo Company
$message->chatSession()  // BelongsTo ChatSession
$message->user()         // BelongsTo User (nullable)

// Scopes úteis
Message::inbound()       // Mensagens recebidas
Message::outbound()      // Mensagens enviadas
Message::fromClient()    // Do cliente
Message::fromBot()       // Do bot
Message::fromHuman()     // De atendente humano
Message::failed()        // Falhas
Message::forCompany($id) // Por empresa
Message::forSession($id) // Por sessão

// Helper methods
$message->isFromClient()
$message->isFromBot()
$message->isFromHuman()
$message->hasMedia()
$message->hasFailed()

// Status tracking
$message->markAsSent()
$message->markAsDelivered()
$message->markAsRead()
$message->markAsFailed($code, $message)
```

### Relacionamentos Bidirecionais

```php
// Company
$company->messages()

// ChatSession
$session->messages()          // Todas as mensagens ordenadas
$session->latestMessage()     // Última mensagem
```

---

## Preparação para Alto Volume

### Estratégias Implementadas

1. **Índices otimizados**: Cobrem queries mais frequentes
2. **Particionamento futuro**: Campo `created_at` permite particionar por data
3. **Cascade deletes**: Limpeza automática ao deletar company/session
4. **Campos nullable**: Evita writes desnecessários
5. **JSON metadata**: Evita JOINs com tabelas auxiliares

### Recomendações Futuras

```sql
-- Particionamento mensal (PostgreSQL/MySQL 8+)
-- ALTER TABLE messages PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at));

-- Archive de mensagens antigas
-- Mover mensagens >6 meses para messages_archive

-- Read replicas para dashboards
-- Separar queries analíticas de transacionais
```

---

## Queries Comuns e Performance

### 1. Histórico de Conversa
```php
Message::forSession($sessionId)
    ->with('user')
    ->orderBy('created_at', 'asc')
    ->get();
// ✅ Usa índice: messages_chat_timeline
```

### 2. Últimas Mensagens por Empresa
```php
Message::forCompany($companyId)
    ->latest()
    ->limit(100)
    ->get();
// ✅ Usa índice: messages_company_timeline
```

### 3. Mensagens Não Lidas
```php
Message::forCompany($companyId)
    ->where('status', '!=', 'read')
    ->inbound()
    ->fromClient()
    ->orderBy('created_at', 'desc')
    ->get();
// ✅ Usa múltiplos índices
```

### 4. Taxa de Resposta do Bot
```php
Message::forCompany($companyId)
    ->fromBot()
    ->whereBetween('created_at', [$start, $end])
    ->count();
// ✅ Usa índice: messages_sender_analytics
```

### 5. Webhook Status Update
```php
Message::where('external_id', $whatsappMessageId)
    ->where('channel', 'whatsapp')
    ->first();
// ✅ Usa índice: messages_external_lookup
```

---

## Exemplo de Uso

### Salvando Mensagem Inbound

```php
Message::create([
    'company_id' => $company->id,
    'chat_session_id' => $session->id,
    'external_id' => $whatsappData['id'],
    'channel' => 'whatsapp',
    'direction' => 'inbound',
    'sender_type' => 'client',
    'content' => $whatsappData['text']['body'],
    'content_type' => 'text',
    'status' => 'delivered',
    'metadata' => [
        'from' => $whatsappData['from'],
        'timestamp' => $whatsappData['timestamp'],
        'profile_name' => $whatsappData['profile']['name'] ?? null,
    ],
]);
```

### Salvando Resposta do Bot

```php
$message = Message::create([
    'company_id' => $company->id,
    'chat_session_id' => $session->id,
    'channel' => 'whatsapp',
    'direction' => 'outbound',
    'sender_type' => 'bot',
    'content' => $response,
    'content_type' => 'text',
    'status' => 'pending',
]);

// Após envio via API
$message->update([
    'external_id' => $apiResponse['messages'][0]['id'],
    'status' => 'sent',
    'sent_at' => now(),
]);
```

### Atendimento Humano

```php
Message::create([
    'company_id' => $company->id,
    'chat_session_id' => $session->id,
    'channel' => 'whatsapp',
    'direction' => 'outbound',
    'sender_type' => 'human',
    'content' => $agentMessage,
    'content_type' => 'text',
    'status' => 'pending',
    'user_id' => auth()->id(), // Atendente logado
]);
```

---

## Resumo das Decisões

| Aspecto | Decisão | Motivo |
|---------|---------|--------|
| **company_id** | Obrigatório | Multi-tenant, isolamento de dados |
| **chat_session_id** | Obrigatório | Vinculação com conversa |
| **direction** | Enum (inbound/outbound) | Clara separação de fluxo |
| **sender_type** | Enum (client/bot/human) | Identificação de origem |
| **content** | TEXT nullable | Mensagens longas, mídia sem texto |
| **status** | Enum + timestamps | Tracking completo do ciclo de vida |
| **external_id** | String nullable | Reconciliação com provedores |
| **channel** | String (default whatsapp) | Preparado para omnichannel |
| **metadata** | JSON | Extensibilidade sem migration |
| **user_id** | FK nullable | Auditoria de atendimento humano |
| **Índices compostos** | 7 índices estratégicos | Performance em alto volume |

---

## Próximos Passos

1. ✅ Rodar migration: `php artisan migrate`
2. ⚠️ Integrar no WebhookController para salvar mensagens recebidas
3. ⚠️ Integrar no ChatFlowService para salvar respostas do bot
4. ⚠️ Criar MessageService para centralizar lógica de envio/recebimento
5. ⚠️ Implementar webhook handlers para status updates do WhatsApp
6. ⚠️ Criar dashboard de mensagens para atendimento humano
7. ⚠️ Implementar relatórios e analytics

---

## Considerações de Segurança

- ✅ Cascade delete protege contra dados órfãos
- ✅ Multi-tenant por design (sempre filtrar por company_id)
- ✅ Foreign keys garantem integridade referencial
- ⚠️ Implementar rate limiting no envio de mensagens
- ⚠️ Criptografar conteúdo sensível se necessário (LGPD)
-
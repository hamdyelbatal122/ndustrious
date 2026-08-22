<?php

declare(strict_types=1);

namespace Hamzi\NativeRag\Models;

use Hamzi\NativeRag\Services\ConversationService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $name
 * @property array<string, mixed>|null $metadata
 */
class NativeRagConversation extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('nativerag.conversations.table_conversations', 'nativerag_conversations');
    }

    /**
     * Use the casts() method (Laravel 11+) — do NOT combine with a $casts property.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        if (config('nativerag.conversations.encrypt_payloads', false)) {
            return [
                'metadata' => 'encrypted:array',
            ];
        }

        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<NativeRagMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(NativeRagMessage::class, 'conversation_id');
    }

    /**
     * Prune old messages according to the configured strategy.
     */
    public function pruneHistory(): void
    {
        $strategy = config('nativerag.conversations.pruning_strategy', 'count');

        if ($strategy === 'count') {
            $maxHistory = (int) config('nativerag.conversations.max_history_count', 10);

            $idsToKeep = $this->messages()
                ->latest()
                ->limit($maxHistory)
                ->pluck('id');

            $this->messages()
                ->whereNotIn('id', $idsToKeep)
                ->delete();
        } elseif ($strategy === 'token') {
            $maxTokens = (int) config('nativerag.conversations.max_tokens_threshold', 4096);
            $totalTokens = 0;
            $idsToKeep = [];

            // Fetch messages from latest to oldest
            $messages = $this->messages()->latest()->get();

            foreach ($messages as $message) {
                // Heuristic approximation if tokens column is not filled: 1 token ~ 4 characters
                $msgTokens = $message->tokens ?? (int) ceil(mb_strlen($message->content, 'UTF-8') / 4);

                // Always keep at least the latest message
                if (empty($idsToKeep) || ($totalTokens + $msgTokens) <= $maxTokens) {
                    $idsToKeep[] = $message->id;
                    $totalTokens += $msgTokens;
                } else {
                    break;
                }
            }

            $this->messages()
                ->whereNotIn('id', $idsToKeep)
                ->delete();
        }
    }

    /**
     * Add a message to the conversation.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function addMessage(string $role, string $content, ?array $metadata = null, ?int $tokens = null, bool $prune = true): NativeRagMessage
    {
        /** @var NativeRagMessage $message */
        $message = $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
            'tokens' => $tokens,
        ]);

        if ($prune) {
            $this->pruneHistory();
        }

        return $message;
    }

    /**
     * Add a system message to the conversation.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function addSystemMessage(string $content, ?array $metadata = null): NativeRagMessage
    {
        return $this->addMessage('system', $content, $metadata);
    }

    /**
     * Add a user message to the conversation.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function addUserMessage(string $content, ?array $metadata = null, bool $prune = true): NativeRagMessage
    {
        return $this->addMessage('user', $content, $metadata, null, $prune);
    }

    /**
     * Add an assistant message to the conversation.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function addAssistantMessage(string $content, ?array $metadata = null, ?int $tokens = null, bool $prune = true): NativeRagMessage
    {
        return $this->addMessage('assistant', $content, $metadata, $tokens, $prune);
    }

    /**
     * Get the conversation history formatted for the chat drivers.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function messagesForChat(): array
    {
        return $this->messages()
            ->oldest()
            ->get(['role', 'content'])
            ->toArray();
    }

    /**
     * Send a new user message to the AI engine, retrieve the response,
     * save both to the database, and return the assistant response message.
     *
     * @param  array<string, mixed>  $options
     */
    public function ask(string $userMessage, array $options = []): NativeRagMessage
    {
        return app(ConversationService::class)->ask($this, $userMessage, $options);
    }
}

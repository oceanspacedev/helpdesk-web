<?php

namespace App\Mcp\Tools;

use App\Enums\TicketWorkflowAction;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\TicketWorkflowService;
use App\Support\HelpdeskIntegrationClient;
use App\Support\HelpdeskWorkflowCommandParser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('helpdesk_ticket_workflow')]
#[Description('Staff-only deterministic ticket transition. Pass the latest user message unchanged. The server accepts exactly one canonical HD-YYYY-NNNNN ticket number and one explicit process/done action, derives the PIC only from the authenticated personal staff credential, and rejects questions, negation, extra prose, malformed/multiple numbers, conflicting actions, and model-supplied identity. Never call for lookup, comments, or assigning another person.')]
class HelpdeskTicketWorkflowTool extends Tool
{
    public function __construct(
        private HelpdeskWorkflowCommandParser $parser,
        private TicketWorkflowService $workflow,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $unknownArguments = array_diff(array_keys($request->all()), ['message']);
        if ($unknownArguments !== []) {
            return $this->error(
                'invalid_input',
                'Perintah tidak dijalankan karena terdapat argumen yang tidak diizinkan.',
                'UNEXPECTED_ARGUMENT',
            );
        }

        $message = $request->get('message');
        if (! is_string($message)) {
            return $this->error(
                'invalid_input',
                'message wajib berupa perintah teks yang disalin persis dari pengguna.',
                'INVALID_MESSAGE',
            );
        }

        $command = $this->parser->parse($message);
        if ($command === null) {
            return $this->error(
                'clarification_required',
                'Perintah tidak dijalankan. Kirim tepat satu perintah seperti `HD-2026-00042 proses` atau `HD-2026-00042 done`. Pertanyaan, negasi, teks tambahan, dua nomor, atau dua aksi tidak akan mengubah tiket.',
                'AMBIGUOUS_COMMAND',
            );
        }

        $actorId = HelpdeskIntegrationClient::currentWorkflowActorId();
        $actor = $actorId ? User::query()->find($actorId) : null;
        if (! $actor || ! $actor->is_active) {
            return $this->error(
                'not_allowed',
                'Perintah tidak dijalankan karena kredensial petugas tidak lagi aktif.',
                'WORKFLOW_ACTOR_UNAVAILABLE',
            );
        }

        $result = $this->workflow->transition(
            $command['ticket_number'],
            $actor,
            $command['action'],
        );
        if (! $result['ok']) {
            return $this->error(
                'not_allowed',
                'Perintah tidak dijalankan. Tiket tidak tersedia untuk aksi ini, statusnya tidak sesuai, atau petugas tidak berwenang memprosesnya.',
                'WORKFLOW_NOT_ALLOWED',
                $command['action'],
            );
        }

        $alreadyApplied = $result['code'] === 'already_applied';
        $statusName = $command['action'] === TicketWorkflowAction::PROCESS
            ? 'In Progress'
            : 'Closed';
        $reply = $alreadyApplied
            ? "{$command['ticket_number']} sudah berstatus {$statusName} dengan PIC {$actor->name}. Tidak ada perubahan ulang."
            : "{$command['ticket_number']} berhasil diubah menjadi {$statusName}. PIC: {$actor->name}.";
        $structured = [
            'ok' => true,
            'status' => $alreadyApplied ? 'already_applied' : 'transitioned',
            'user_reply' => $reply,
            'action' => $command['action']->value,
            'ticket' => [
                'ticket_number' => $command['ticket_number'],
                'status_id' => $command['action'] === TicketWorkflowAction::PROCESS
                    ? TicketStatus::IN_PROGRESS
                    : TicketStatus::CLOSED,
                'status' => $statusName,
                'pic' => $actor->name,
            ],
            'data' => [
                'error_code' => null,
                'replayed' => $alreadyApplied,
            ],
        ];

        return (new ResponseFactory(Response::text($reply)))
            ->withStructuredContent($structured);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->min(1)
                ->max(500)
                ->description('The exact latest user message, copied unchanged. Do not summarize, translate, correct, or add a PIC/user identity.')
                ->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'ok' => $schema->boolean()->required(),
            'status' => $schema->string()->required(),
            'user_reply' => $schema->string()->required(),
            'action' => $schema->string()->enum(['process', 'done'])->nullable()->required(),
            'ticket' => $schema->object([
                'ticket_number' => $schema->string(),
                'status_id' => $schema->integer(),
                'status' => $schema->string(),
                'pic' => $schema->string(),
            ])->nullable()->required(),
            'data' => $schema->object([
                'error_code' => $schema->string()->nullable(),
                'replayed' => $schema->boolean(),
            ])->required(),
        ];
    }

    private function error(
        string $status,
        string $message,
        string $errorCode,
        ?TicketWorkflowAction $action = null,
    ): ResponseFactory {
        $structured = [
            'ok' => false,
            'status' => $status,
            'user_reply' => $message,
            'action' => $action?->value,
            'ticket' => null,
            'data' => [
                'error_code' => $errorCode,
                'replayed' => false,
            ],
        ];

        return (new ResponseFactory(Response::error($message)))
            ->withStructuredContent($structured);
    }
}

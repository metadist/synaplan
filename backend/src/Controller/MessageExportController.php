<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\Chat\ChatActionPolicy;
use App\Service\Research\Export\ReplyExportFormat;
use App\Service\Research\Export\ResearchReplyExporter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Downloads one reply as a Word document, a web page or Markdown.
 */
#[Route('/api/v1/messages', name: 'api_message_export_')]
final class MessageExportController extends AbstractController
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly ResearchReplyExporter $exporter,
        private readonly ChatActionPolicy $actions,
    ) {
    }

    #[Route('/{messageId}/export', name: 'download', methods: ['GET'], requirements: ['messageId' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/messages/{messageId}/export',
        summary: 'Download one reply as a Word document, a web page or Markdown',
        description: 'The file holds the question as title, the reply with its tables and the numbered list of its sources. The web page is self-contained: no script and nothing loaded from elsewhere.',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'messageId', in: 'path', required: true, description: 'The reply to download', schema: new OA\Schema(type: 'integer', example: 1234)),
            new OA\Parameter(name: 'format', in: 'query', required: false, description: 'File format', schema: new OA\Schema(type: 'string', enum: ['docx', 'html', 'md'], default: 'docx', example: 'docx')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The reply as a file, sent as an attachment',
                headers: [
                    new OA\Header(header: 'Content-Disposition', description: 'attachment with the file name taken from the title', schema: new OA\Schema(type: 'string', example: 'attachment; filename=heat-pumps-compared.docx')),
                ],
                content: [
                    new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', schema: new OA\Schema(type: 'string', format: 'binary')),
                    new OA\MediaType(mediaType: 'text/html', schema: new OA\Schema(type: 'string', example: '<!DOCTYPE html>…')),
                    new OA\MediaType(mediaType: 'text/markdown', schema: new OA\Schema(type: 'string', example: "# Heat pumps compared\n\n…")),
                ],
            ),
            new OA\Response(response: 400, description: 'Unknown format', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string', example: 'Unknown format. Use docx, html or md.')])),
            new OA\Response(response: 401, description: 'Not authenticated', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string', example: 'Not authenticated')])),
            new OA\Response(response: 403, description: 'Exporting chats is turned off', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string', example: 'Exporting chats is turned off for your account')])),
            new OA\Response(response: 404, description: 'No reply of this account with this id, or it has no text yet', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string', example: 'Message not found')])),
        ]
    )]
    public function download(int $messageId, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->actions->canExport($user->getId())) {
            return $this->json(['error' => 'Exporting chats is turned off for your account'], Response::HTTP_FORBIDDEN);
        }
        $format = ReplyExportFormat::tryFrom((string) $request->query->get('format', ReplyExportFormat::Docx->value));
        if (null === $format) {
            return $this->json(['error' => 'Unknown format. Use docx, html or md.'], Response::HTTP_BAD_REQUEST);
        }
        $reply = $this->messages->find($messageId);
        if (!$reply instanceof Message || $reply->getUserId() !== $user->getId() || 'OUT' !== $reply->getDirection() || '' === trim($reply->getText())) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $file = $this->exporter->export($reply, $format, $user->getLocale());

        return new Response($file->content, Response::HTTP_OK, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->fileName, $file->asciiFileName),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

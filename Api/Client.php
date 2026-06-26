<?php

declare(strict_types=1);

namespace Box\Mod\Supportticketattachments\Api;

use FOSSBilling\Exception;
use FOSSBilling\Validation\Api\RequiredParams;

/**
 * Support Ticket Attachments — Client API
 *
 * All methods require the client to be logged in (enforced by AbstractApi).
 *
 * API endpoints (FOSSBilling pattern: module_method):
 *   POST   /api/client/Supportticketattachments/upload
 *   POST   /api/client/Supportticketattachments/get_list
 *   POST   /api/client/Supportticketattachments/delete
 */
class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Upload a file attachment for a support ticket.
     *
     * Required POST fields:
     *   - ticket_id  (int)
     *
     * Required FILES:
     *   - file  (the uploaded file)
     *
     * @return array{id: int, original_filename: string, file_size: int, mime_type: string, created_at: string}
     * @throws Exception
     */
    #[RequiredParams(['ticket_id' => 'Ticket ID is required'])]
    public function upload(array $data): array
    {
        $client    = $this->getIdentity();
        $ticketId  = (int) $data['ticket_id'];
        $clientId  = (int) $client->id;

        /** @var \Box\Mod\Supportticketattachments\Service $service */
        $service = $this->di['mod_service']('Supportticketattachments');

        // Verify ticket belongs to this client
        $service->verifyTicketOwnership($ticketId, $clientId);

        // Get uploaded file from Symfony request
        $request = $this->getDi()['request'];
        $file    = $request->files->get('file');

        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            throw new Exception('No file was uploaded. Please select a file.');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed with error code: :code', [':code' => $file->getError()]);
        }

        // Save file and get new ID
        $newId = $service->saveUpload($file, $ticketId, $clientId);

        // Return the created record
        $attachment = $service->getAttachmentById($newId);

        return [
            'id'                => (int) $attachment['id'],
            'original_filename' => $attachment['original_filename'],
            'file_size'         => (int) $attachment['file_size'],
            'file_size_human'   => \Box\Mod\Supportticketattachments\Service::formatBytes((int) $attachment['file_size']),
            'mime_type'         => $attachment['mime_type'],
            'created_at'        => $attachment['created_at'],
        ];
    }


}

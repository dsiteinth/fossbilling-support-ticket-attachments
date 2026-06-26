<?php

declare(strict_types=1);

namespace Box\Mod\Supportticketattachments\Api;

use FOSSBilling\Exception;
use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{


    /**
     * Upload a file attachment for a support ticket.
     */
    #[RequiredParams(['ticket_id' => 'Ticket ID is required.'])]
    public function upload(array $data): array
    {
        $ticketId  = (int) $data['ticket_id'];

        /** @var \Box\Mod\Supportticketattachments\Service $service */
        $service = $this->di['mod_service']('Supportticketattachments');

        // To assign the correct client_id, we fetch the ticket from the DB
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('SELECT `client_id` FROM `support_ticket` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $ticketId]);
        $clientId = (int) $stmt->fetchColumn();

        if (!$clientId) {
            throw new Exception('Ticket not found.', null, 404);
        }

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

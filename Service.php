<?php

declare(strict_types=1);

namespace Box\Mod\Supportticketattachments;

use FOSSBilling\InjectionAwareInterface;
use Symfony\Component\Filesystem\Filesystem;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    // Allowed file extensions
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip'];

    // Allowed MIME types
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'application/zip',
        'application/x-zip-compressed',
    ];

    // Max file size: 5 MB
    private const MAX_FILE_SIZE = 5242880;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Called when the module is installed/activated.
     * Creates the database table and upload directory.
     */
    public function install(): bool
    {
        $pdo = $this->di['pdo'];

        $sql = "
            CREATE TABLE IF NOT EXISTS `support_ticket_attachment` (
                `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `ticket_id`         INT UNSIGNED    NOT NULL,
                `client_id`         INT UNSIGNED    NOT NULL,
                `message_id`        INT UNSIGNED    DEFAULT NULL,
                `original_filename` VARCHAR(255)    NOT NULL,
                `stored_filename`   VARCHAR(255)    NOT NULL,
                `file_size`         INT UNSIGNED    NOT NULL DEFAULT 0,
                `mime_type`         VARCHAR(100)    NOT NULL DEFAULT '',
                `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_ticket_id` (`ticket_id`),
                INDEX `idx_client_id` (`client_id`),
                INDEX `idx_message_id` (`message_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $pdo->exec($sql);

        // Migration for existing tables
        try {
            $pdo->exec("ALTER TABLE `support_ticket_attachment` ADD COLUMN `message_id` INT UNSIGNED DEFAULT NULL AFTER `client_id`");
            $pdo->exec("CREATE INDEX `idx_message_id` ON `support_ticket_attachment` (`message_id`)");
        } catch (\PDOException $e) {
            // Column likely already exists
        }

        // Create upload directory
        $uploadDir = $this->getUploadDir();
        $fs = new Filesystem();
        if (!$fs->exists($uploadDir)) {
            $fs->mkdir($uploadDir, 0750);
        }

        return true;
    }

    /**
     * Called when the module is uninstalled/deactivated.
     * Drops the table and removes uploaded files.
     */
    public function uninstall(): bool
    {
        $pdo = $this->di['pdo'];
        $pdo->exec('DROP TABLE IF EXISTS `support_ticket_attachment`');

        // Remove all uploaded files
        $uploadDir = $this->getUploadDir();
        $fs = new Filesystem();
        if ($fs->exists($uploadDir)) {
            $fs->remove($uploadDir);
        }

        return true;
    }

    /**
     * Returns the directory used to store attachment files.
     */
    public function getUploadDir(): string
    {
        return PATH_DATA . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sta';
    }

    /**
     * Returns the list of allowed file extensions.
     */
    public function getAllowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * Returns the max allowed file size in bytes.
     */
    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }

    /**
     * Validates and saves an uploaded file, returning the new DB record ID.
     *
     * @param \Symfony\Component\HttpFoundation\File\UploadedFile $file
     * @param int $ticketId
     * @param int $clientId
     * @return int  The new attachment ID
     * @throws \FOSSBilling\Exception
     */
    public function saveUpload(\Symfony\Component\HttpFoundation\File\UploadedFile $file, int $ticketId, int $clientId): int
    {
        // Check extension early
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new \FOSSBilling\Exception(
                'File type ":ext" is not allowed. Allowed types: :allowed',
                [':ext' => $ext, ':allowed' => implode(', ', self::ALLOWED_EXTENSIONS)]
            );
        }

        // Generate a random stored filename
        $storedFilename = bin2hex(random_bytes(16)) . '.' . $ext;
        $uploadDir = $this->getUploadDir();

        // Ensure directory exists
        $fs = new Filesystem();
        if (!$fs->exists($uploadDir)) {
            $fs->mkdir($uploadDir, 0750);
        }

        // Move the file FIRST to avoid open_basedir stat restrictions on /tmp
        $targetFile = $file->move($uploadDir, $storedFilename);

        // Now we can safely check size and mime type
        $fileSize = $targetFile->getSize();
        if ($fileSize > self::MAX_FILE_SIZE) {
            $fs->remove($targetFile->getPathname());
            throw new \FOSSBilling\Exception('File is too large. Maximum allowed size is 5 MB.');
        }

        $mime = strtolower((string) $targetFile->getMimeType());
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            $fs->remove($targetFile->getPathname());
            throw new \FOSSBilling\Exception(
                'File MIME type ":mime" is not allowed.',
                [':mime' => $mime]
            );
        }

        // Save record to DB
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare(
            'INSERT INTO `support_ticket_attachment`
             (`ticket_id`, `client_id`, `original_filename`, `stored_filename`, `file_size`, `mime_type`)
             VALUES (:ticket_id, :client_id, :original_filename, :stored_filename, :file_size, :mime_type)'
        );
        $stmt->execute([
            ':ticket_id'         => $ticketId,
            ':client_id'         => $clientId,
            ':original_filename' => $file->getClientOriginalName(),
            ':stored_filename'   => $storedFilename,
            ':file_size'         => $fileSize,
            ':mime_type'         => $mime,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Returns all attachments for a given ticket.
     */
    public function getAttachmentsByTicket(int $ticketId): array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare(
            'SELECT `id`, `ticket_id`, `client_id`, `message_id`, `original_filename`, `file_size`, `mime_type`, `created_at`
             FROM `support_ticket_attachment`
             WHERE `ticket_id` = :ticket_id
             ORDER BY `created_at` ASC'
        );
        $stmt->execute([':ticket_id' => $ticketId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Returns a single attachment row, or null if not found.
     */
    public function getAttachmentById(int $id): ?array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare(
            'SELECT * FROM `support_ticket_attachment` WHERE `id` = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Deletes an attachment record and its file from disk.
     */
    public function deleteAttachment(int $id): bool
    {
        $attachment = $this->getAttachmentById($id);
        if (!$attachment) {
            return false;
        }

        // Remove file from disk
        $filePath = $this->getUploadDir() . DIRECTORY_SEPARATOR . $attachment['stored_filename'];
        $fs = new Filesystem();
        if ($fs->exists($filePath)) {
            $fs->remove($filePath);
        }

        // Remove DB record
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('DELETE FROM `support_ticket_attachment` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);

        return true;
    }

    /**
     * Verifies that a support ticket belongs to the given client.
     *
     * @throws \FOSSBilling\Exception if ticket is not found or does not belong to client
     */
    public function verifyTicketOwnership(int $ticketId, int $clientId): void
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare(
            'SELECT `id` FROM `support_ticket` WHERE `id` = :id AND `client_id` = :client_id LIMIT 1'
        );
        $stmt->execute([':id' => $ticketId, ':client_id' => $clientId]);

        if (!$stmt->fetch()) {
            throw new \FOSSBilling\Exception('Ticket not found or access denied.', null, 404);
        }
    }

    /**
     * Format bytes into human-readable size string.
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Link all currently unlinked attachments for a ticket to a specific message ID
     */
    public function linkAttachmentsToMessage(int $ticketId, int $messageId): void
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('UPDATE `support_ticket_attachment` SET `message_id` = :message_id WHERE `ticket_id` = :ticket_id AND `message_id` IS NULL');
        $stmt->execute([':message_id' => $messageId, ':ticket_id' => $ticketId]);
    }

    /**
     * Find the latest message ID for a ticket and link unlinked attachments to it
     */
    public function autoLinkLatestMessage(int $ticketId): void
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('SELECT `id` FROM `support_ticket_message` WHERE `support_ticket_id` = :ticket_id ORDER BY `id` DESC LIMIT 1');
        $stmt->execute([':ticket_id' => $ticketId]);
        $messageId = $stmt->fetchColumn();

        if ($messageId) {
            $this->linkAttachmentsToMessage($ticketId, (int) $messageId);
        }
    }

    public static function onAfterClientReplyTicket(\Box_Event $event)
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        if (!empty($params['id'])) {
            $di['mod_service']('Supportticketattachments')->autoLinkLatestMessage((int) $params['id']);
        }
    }

    public static function onAfterAdminReplyTicket(\Box_Event $event)
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        if (!empty($params['id'])) {
            $di['mod_service']('Supportticketattachments')->autoLinkLatestMessage((int) $params['id']);
        }
    }

    public static function onAfterClientOpenTicket(\Box_Event $event)
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        if (!empty($params['id'])) {
            $di['mod_service']('Supportticketattachments')->autoLinkLatestMessage((int) $params['id']);
        }
    }

    public static function onAfterAdminOpenTicket(\Box_Event $event)
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        if (!empty($params['id'])) {
            $di['mod_service']('Supportticketattachments')->autoLinkLatestMessage((int) $params['id']);
        }
    }

    /**
     * Clean up attachments when a ticket is deleted by admin
     */
    public static function onAfterAdminDeleteTicket(\Box_Event $event)
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        if (!empty($params['id'])) {
            $service = $di['mod_service']('Supportticketattachments');
            $attachments = $service->getAttachmentsByTicket((int) $params['id']);
            foreach ($attachments as $att) {
                $service->deleteAttachment((int) $att['id']);
            }
        }
    }

    /**
     * Clean up attachments when a ticket is deleted by client
     */
    public static function onAfterClientDeleteTicket(\Box_Event $event)
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        if (!empty($params['id'])) {
            $service = $di['mod_service']('Supportticketattachments');
            $attachments = $service->getAttachmentsByTicket((int) $params['id']);
            foreach ($attachments as $att) {
                $service->deleteAttachment((int) $att['id']);
            }
        }
    }
}

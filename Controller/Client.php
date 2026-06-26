<?php

declare(strict_types=1);

namespace Box\Mod\Supportticketattachments\Controller;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/supportticketattachments/download/:id', 'get_download', ['id' => '[0-9]+'], static::class);
    }

    public function get_download(\Box_App $app, $id): void
    {
        $id = (int) $id;
        
        /** @var \Box\Mod\Supportticketattachments\Service $service */
        $service = $this->di['mod_service']('Supportticketattachments');
        $attachment = $service->getAttachmentById($id);
        
        if (!$attachment) {
            $app->redirect('/');
            return;
        }
        
        // Authentication check: Must be client or admin
        $isClient = $this->di['auth']->isClientLoggedIn();
        $isAdmin  = $this->di['auth']->isAdminLoggedIn();
        
        if (!$isClient && !$isAdmin) {
            // Check if it's a guest ticket hash access (optional, but let's just deny for now)
            $app->redirect('/login');
            return;
        }
        
        // If it's a client (and not admin), verify ticket ownership
        if ($isClient && !$isAdmin) {
            $client = $this->di['loggedin_client'];
            try {
                $service->verifyTicketOwnership((int)$attachment['ticket_id'], (int)$client->id);
            } catch (\Exception $e) {
                // Not the owner
                $app->redirect('/support');
                return;
            }
        }
        
        $filePath = $service->getUploadDir() . DIRECTORY_SEPARATOR . $attachment['stored_filename'];
        
        if (!file_exists($filePath)) {
            $app->redirect('/');
            return;
        }
        
        $mime = $attachment['mime_type'] ?: 'application/octet-stream';
        $filename = $attachment['original_filename'];
        $size = filesize($filePath);
        
        // If it's an image, allow inline display. Otherwise, force download.
        $disposition = str_starts_with($mime, 'image/') ? 'inline' : 'attachment';
        
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Cache-Control: public, max-age=86400');
        
        readfile($filePath);
        exit;
    }
}

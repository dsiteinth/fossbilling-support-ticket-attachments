<?php

declare(strict_types=1);

namespace Box\Mod\Supportticketattachments\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        return [];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/supportticketattachments', 'get_index', [], static::class);
        $app->get('/supportticketattachments/download/:id', 'get_download', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        return $app->render('mod_supportticketattachments_index');
    }

    public function get_download(\Box_App $app, $id): void
    {
        $id = (int) $id;
        
        $this->di['is_admin_logged'];
        
        /** @var \Box\Mod\Supportticketattachments\Service $service */
        $service = $this->di['mod_service']('Supportticketattachments');
        $attachment = $service->getAttachmentById($id);
        
        if (!$attachment) {
            $app->redirect('/admin/support');
            return;
        }
        
        $filePath = $service->getUploadDir() . DIRECTORY_SEPARATOR . $attachment['stored_filename'];
        
        if (!file_exists($filePath)) {
            $app->redirect('/admin/support');
            return;
        }
        
        $mime = $attachment['mime_type'] ?: 'application/octet-stream';
        $filename = $attachment['original_filename'];
        $size = filesize($filePath);
        
        $disposition = str_starts_with($mime, 'image/') ? 'inline' : 'attachment';
        
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Cache-Control: public, max-age=86400');
        
        readfile($filePath);
        exit;
    }
}

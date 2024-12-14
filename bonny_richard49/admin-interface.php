<?php

namespace App\Core\Admin;

use App\Core\Security\CoreSecurityManager;
use App\Core\Template\AdminTemplate;
use App\Core\Content\ContentManager;

class AdminController
{
    private CoreSecurityManager $security;
    private AdminTemplate $template;
    private ContentManager $content;

    public function dashboard()
    {
        return $this->security->executeSecureOperation(
            fn() => $this->template->dashboard([
                'content' => $this->content->getRecent(),
                'user' => auth()->user()
            ]),
            ['permission' => 'admin.access']
        );
    }

    public function editContent(int $id)
    {
        return $this->security->executeSecureOperation(
            fn() => $this->template->contentEditor([
                'content' => $this->content->getContent($id),
                'categories' => $this->content->getCategories()
            ]),
            ['permission' => 'content.edit']
        );
    }

    public function saveContent(int $id, array $data)
    {
        return $this->security->executeSecureOperation(
            fn() => $this->content->updateContent($id, $data),
            [
                'permission' => 'content.edit',
                'data' => $data
            ]
        );
    }
}

class MediaController 
{
    private CoreSecurityManager $security;
    private MediaManager $media;

    public function upload(array $file)
    {
        return $this->security->executeSecureOperation(
            fn() => $this->media->store($file),
            [
                'permission' => 'media.upload',
                'file' => $file
            ]
        );
    }

    public function browse()
    {
        return $this->security->executeSecureOperation(
            fn() => $this->media->getAll(),
            ['permission' => 'media.view']
        );
    }
}

class MediaManager
{
    private string $storagePath;
    
    public function store(array $file)
    {
        $path = $file['file']->store('media', 'public');
        return ['url' => asset("storage/$path")];
    }

    public function getAll(): array
    {
        return Storage::files('public/media');
    }
}

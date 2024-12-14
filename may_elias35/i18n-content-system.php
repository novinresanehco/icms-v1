// File: app/Core/I18n/Content/ContentManager.php
<?php

namespace App\Core\I18n\Content;

class ContentManager
{
    protected ContentRepository $repository;
    protected ContentValidator $validator;
    protected TranslationManager $translationManager;

    public function translate(Content $content, string $locale): TranslatedContent
    {
        if (!$this->validator->canTranslate($content, $locale)) {
            throw new TranslationException("Cannot translate content to locale: {$locale}");
        }

        $translation = $this->repository->findTranslation($content, $locale);
        
        if (!$translation) {
            $translation = $this->createTranslation($content, $locale);
        }

        return $translation;
    }

    public function updateTranslation(Content $content, string $locale, array $data): TranslatedContent
    {
        $translation = $this->repository->findTranslation($content, $locale);
        
        if (!$translation) {
            throw new TranslationNotFoundException();
        }

        $this->validator->validateTranslation($data);
        
        return $this->repository->updateTranslation($translation, $data);
    }

    protected function createTranslation(Content $content, string $locale): TranslatedContent
    {
        return $this->repository->createTranslation($content, $locale, [
            'title' => $content->getTitle(),
            'content' => $content->getContent(),
            'metadata' => $content->getMetadata()
        ]);
    }
}

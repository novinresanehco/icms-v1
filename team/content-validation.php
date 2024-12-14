namespace App\Services\Validation;

class ContentValidationService implements ValidationInterface
{
    private SecurityValidator $security;
    private ConfigRepository $config;
    private ContentRules $rules;

    public function validateContentData(array $data): array 
    {
        // Validate basic structure
        $this->validateStructure($data);
        
        // Security validation
        $this->security->validateInput($data);
        
        // Apply content rules
        return $this->applyContentRules($data);
    }

    public function validateContent(Content $content): bool 
    {
        return $this->validateStructure($content->toArray()) &&
               $this->validateRelations($content) &&
               $this->validateMetadata($content) &&
               $this->security->validateEntity($content);
    }

    private function validateStructure(array $data): bool 
    {
        $requiredFields = $this->rules->getRequiredFields();
        
        foreach ($requiredFields as $field => $type) {
            if (!isset($data[$field]) || gettype($data[$field]) !== $type) {
                throw new ValidationException("Invalid field: $field");
            }
        }
        
        return true;
    }

    private function applyContentRules(array $data): array 
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string'],
            'status' => ['required', 'in:draft,published'],
            'author_id' => ['required', 'exists:users,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'tags' => ['array'],
            'metadata' => ['array']
        ];

        return $this->validator->validate($data, $rules);
    }

    private function validateRelations(Content $content): bool 
    {
        return $this->validateAuthor($content->author_id) &&
               $this->validateCategory($content->category_id) &&
               $this->validateTags($content->tags);
    }

    private function validateMetadata(Content $content): bool 
    {
        if (!is_array($content->metadata)) {
            return false;
        }

        $requiredMeta = $this->config->get('cms.required_metadata');
        
        foreach ($requiredMeta as $key) {
            if (!isset($content->metadata[$key])) {
                return false;
            }
        }

        return true;
    }
}

```php
namespace App\Core\Validation;

use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Validator;

abstract class BaseValidator
{
    protected array $rules = [];
    protected array $messages = [];
    
    public function validate(array $data, ?int $id = null): bool
    {
        $rules = $this->getRules($id);
        
        $validator = Validator::make($data, $rules, $this->messages);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
        
        return true;
    }
    
    protected function getRules(?int $id = null): array
    {
        return $this->rules;
    }
}

class ContentValidator extends BaseValidator
{
    protected array $rules = [
        'title' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:contents,slug',
        'content' => 'required|string',
        'status' => 'required|in:draft,published,archived',
        'author_id' => 'required|exists:users,id',
        'tags' => 'sometimes|array',
        'tags.*' => 'exists:tags,id',
        'media' => 'sometimes|array',
        'media.*' => 'exists:media,id'
    ];

    protected array $messages = [
        'title.required' => 'Content title is required',
        'slug.unique' => 'This URL slug is already in use',
        'tags.*.exists' => 'One or more selected tags do not exist',
        'media.*.exists' => 'One or more selected media items do not exist'
    ];

    protected function getRules(?int $id = null): array
    {
        $rules = $this->rules;
        
        if ($id) {
            $rules['slug'] = "required|string|max:255|unique:contents,slug,{$id}";
        }
        
        return $rules;
    }
}

class TagValidator extends BaseValidator
{
    protected array $rules = [
        'name' => 'required|string|max:50|unique:tags,name',
        'slug' => 'required|string|max:50|unique:tags,slug',
        'description' => 'sometimes|string|max:255'
    ];

    protected array $messages = [
        'name.unique' => 'This tag name already exists',
        'slug.unique' => 'This tag slug already exists'
    ];

    protected function getRules(?int $id = null): array
    {
        $rules = $this->rules;
        
        if ($id) {
            $rules['name'] = "required|string|max:50|unique:tags,name,{$id}";
            $rules['slug'] = "required|string|max:50|unique:tags,slug,{$id}";
        }
        
        return $rules;
    }
}

class MediaValidator extends BaseValidator
{
    protected array $rules = [
        'file' => 'required|file|max:10240|mimes:jpeg,png,pdf,doc,docx',
        'type' => 'required|string|in:image,document,video',
        'metadata' => 'sometimes|array',
        'metadata.alt_text' => 'sometimes|string|max:255',
        'metadata.caption' => 'sometimes|string|max:500'
    ];

    protected array $messages = [
        'file.max' => 'File size cannot exceed 10MB',
        'file.mimes' => 'Invalid file type. Allowed types: jpeg, png, pdf, doc, docx'
    ];
}

namespace App\Core\Criteria;

use Illuminate\Database\Eloquent\Builder;

interface CriteriaInterface
{
    public function apply(Builder $query): Builder;
}

abstract class BaseCriteria implements CriteriaInterface
{
    protected array $parameters;
    
    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
    }
}

class PublishedCriteria extends BaseCriteria
{
    public function apply(Builder $query): Builder
    {
        return $query->where('status', 'published')
                    ->where('published_at', '<=', now());
    }
}

class TaggedWithCriteria extends BaseCriteria
{
    public function apply(Builder $query): Builder
    {
        if (empty($this->parameters['tags'])) {
            return $query;
        }
        
        return $query->whereHas('tags', function ($query) {
            $query->whereIn('id', $this->parameters['tags']);
        });
    }
}

class SearchCriteria extends BaseCriteria
{
    public function apply(Builder $query): Builder
    {
        if (empty($this->parameters['search'])) {
            return $query;
        }
        
        $search = $this->parameters['search'];
        
        return $query->where(function ($query) use ($search) {
            $query->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
        });
    }
}

class DateRangeCriteria extends BaseCriteria
{
    public function apply(Builder $query): Builder
    {
        if (empty($this->parameters['start_date']) && empty($this->parameters['end_date'])) {
            return $query;
        }
        
        if (!empty($this->parameters['start_date'])) {
            $query->where('created_at', '>=', $this->parameters['start_date']);
        }
        
        if (!empty($this->parameters['end_date'])) {
            $query->where('created_at', '<=', $this->parameters['end_date']);
        }
        
        return $query;
    }
}

class OrderByCriteria extends BaseCriteria
{
    public function apply(Builder $query): Builder
    {
        $field = $this->parameters['field'] ?? 'created_at';
        $direction = $this->parameters['direction'] ?? 'desc';
        
        return $query->orderBy($field, $direction);
    }
}

// Example usage in repository:
class ContentRepository extends BaseRepository
{
    public function findWithCriteria(array $criteria): Collection
    {
        foreach ($criteria as $criterion) {
            $this->model = $criterion->apply($this->model->newQuery());
        }
        
        return $this->model->get();
    }

    public function getPublishedContentWithTags(array $tagIds): Collection
    {
        return $this->findWithCriteria([
            new PublishedCriteria(),
            new TaggedWithCriteria(['tags' => $tagIds]),
            new OrderByCriteria(['field' => 'published_at'])
        ]);
    }

    public function searchContent(string $query, array $tags = []): Collection
    {
        return $this->findWithCriteria([
            new SearchCriteria(['search' => $query]),
            new TaggedWithCriteria(['tags' => $tags]),
            new PublishedCriteria(),
            new OrderByCriteria()
        ]);
    }
}
```

This implementation includes:

1. Base Validator with:
   - Common validation logic
   - Error handling
   - Support for updating existing records

2. Specific Validators:
   - ContentValidator
   - TagValidator
   - MediaValidator

3. Criteria Pattern Implementation:
   - BaseCriteria
   - Various specific criteria classes
   - Example usage in repository

4. Key Features:
   - Flexible validation rules
   - Custom error messages
   - Reusable criteria
   - Query builder support
   - Complex search capabilities

Would you like me to continue with:
1. Testing implementations for validators and criteria
2. Additional criteria patterns
3. Advanced validation scenarios
4. Integration with the service layer
5. Cache strategy for filtered queries

Please let me know which aspect you'd like to focus on next.
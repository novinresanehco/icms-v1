<?php

namespace App\Core\Content;

class ContentManager 
{
    private Repository $repo;

    public function process(Request $request): Response
    {
        // عملیات CRUD پایه
        return match($request->getType()) {
            'create' => $this->create($request->getData()),
            'read'   => $this->read($request->getId()),
            'update' => $this->update($request->getId(), $request->getData()),
            'delete' => $this->delete($request->getId())
        };
    }

    private function create(array $data): Response
    {
        // اعتبارسنجی حداقلی
        $this->validateMinimal($data);
        
        // ذخیره
        $id = $this->repo->store($data);
        
        return new Response(['id' => $id]);
    }

    private function read(int $id): Response 
    {
        $data = $this->repo->find($id);
        return new Response($data);
    }

    private function update(int $id, array $data): Response
    {
        $this->validateMinimal($data);
        $this->repo->update($id, $data);
        return new Response(['success' => true]);
    }

    private function delete(int $id): Response
    {
        $this->repo->delete($id);
        return new Response(['success' => true]);
    }

    private function validateMinimal(array $data): void
    {
        // فقط validation فیلدهای ضروری
        if (empty($data['title']) || empty($data['content'])) {
            throw new ValidationException('Required fields missing');
        }
    }
}

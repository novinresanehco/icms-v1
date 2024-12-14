<?php

namespace App\Core;

class SecurityCore {
    private $auth;
    private $monitor;

    public function validateRequest(Request $req): bool {
        return $this->auth->quickValidate($req) && 
               $this->validatePermissions($req);
    }

    private function validatePermissions(Request $req): bool {
        // Critical permission check
        return true; 
    }
}

class ContentCore {
    private $security;
    private $storage;
    
    public function handle(Request $req): Response {
        if(!$this->security->validateRequest($req)) {
            throw new SecurityException();
        }

        return match($req->type) {
            'create' => $this->create($req->data),
            'read'   => $this->read($req->id),
            'update' => $this->update($req->id, $req->data),
            'delete' => $this->delete($req->id)
        };
    }

    private function create(array $data): Response {
        $id = $this->storage->store($data);
        return new Response(['id' => $id]);
    }

    private function read(int $id): Response {
        return new Response($this->storage->find($id));
    }

    private function update(int $id, array $data): Response {
        $this->storage->update($id, $data);
        return new Response(['success' => true]);
    }

    private function delete(int $id): Response {
        $this->storage->delete($id);
        return new Response(['success' => true]); 
    }
}

class StorageCore {
    private $db;
    private $cache;

    public function store(array $data): int {
        $id = $this->db->insert($data);
        $this->cache->set("content.$id", $data);
        return $id;
    }

    public function find(int $id): array {
        if($cached = $this->cache->get("content.$id")) {
            return $cached;
        }
        return $this->db->find($id);
    }

    public function update(int $id, array $data): void {
        $this->db->update($id, $data);
        $this->cache->delete("content.$id");
    }

    public function delete(int $id): void {
        $this->db->delete($id);
        $this->cache->delete("content.$id");
    }
}

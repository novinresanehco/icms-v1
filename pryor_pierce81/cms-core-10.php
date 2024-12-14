<?php

namespace App\Core\CMS;

class CoreCMS implements CMSInterface
{
    private SecurityManager $security;
    private ContentManager $content;
    private MonitoringService $monitor;

    public function __construct(
        SecurityManager $security,
        ContentManager $content,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->monitor = $monitor;
    }

    public function handleRequest(Request $request): Response
    {
        $this->monitor->startRequest();
        
        try {
            // Critical security validation
            $this->security->validateRequest($request);
            
            // Process content operation
            $result = match($request->getOperation()) {
                'create' => $this->create($request),
                'update' => $this->update($request),
                'delete' => $this->delete($request),
                'read'   => $this->read($request),
                default  => throw new InvalidOperationException()
            };

            $this->monitor->trackSuccess();
            return $result;

        } catch (\Exception $e) {
            $this->monitor->trackFailure($e);
            throw $e;
        }
    }

    private function create(Request $request): Response 
    {
        // Validate input with zero tolerance
        $data = $this->content->validateInput($request->getData());
        
        // Create with security checks
        $id = $this->content->create($data);
        
        return new Response(['id' => $id]);
    }

    private function update(Request $request): Response
    {
        $data = $this->content->validateInput($request->getData());
        $this->content->update($request->getId(), $data);
        return new Response(['success' => true]);
    }

    private function delete(Request $request): Response
    {
        $this->content->delete($request->getId());
        return new Response(['success' => true]);
    }

    private function read(Request $request): Response
    {
        $data = $this->content->read($request->getId());
        return new Response($data);
    }
}

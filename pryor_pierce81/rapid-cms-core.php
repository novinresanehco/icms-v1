<?php

namespace App\Core;

class RapidCMSCore
{
    private RapidSecurityManager $security;
    private ContentManager $content;
    private CacheManager $cache;

    public function __construct(
        RapidSecurityManager $security,
        ContentManager $content,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->cache = $cache;
    }

    public function handleRequest(Request $request): Response
    {
        try {
            // امنیت حداقلی و ضروری
            $this->security->validateQuick($request);

            // کش برای سرعت
            if ($cached = $this->cache->get($request->getKey())) {
                return $cached;
            }

            // پردازش محتوا
            $result = $this->content->process($request);
            
            // ذخیره در کش
            $this->cache->set($request->getKey(), $result);

            return $result;

        } catch (SecurityException $e) {
            throw $e;
        }
    }
}

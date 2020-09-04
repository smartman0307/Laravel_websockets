<?php

namespace BeyondCode\LaravelWebSockets\Apps;

interface AppManager
{
    /**
     * Get all apps.
     *
     * @return array[\BeyondCode\LaravelWebSockets\Apps\App]
     */
    public function all(): array;

    /**
     * Get app by id.
     *
     * @param  int  $appId
     * @return \BeyondCode\LaravelWebSockets\Apps\App|null
     */
    public function findById($appId): ?App;

    /**
     * Get app by app key.
     *
     * @param  string  $appKey
     * @return \BeyondCode\LaravelWebSockets\Apps\App|null
     */
    public function findByKey($appKey): ?App;

    /**
     * Get app by secret.
     *
     * @param  string  $appSecret
     * @return \BeyondCode\LaravelWebSockets\Apps\App|null
     */
    public function findBySecret($appSecret): ?App;
}

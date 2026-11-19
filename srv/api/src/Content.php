<?php

namespace Lihzorg\PhpFpmSrvApi;

class Content
{
    public array $config;
    public Http $http;
    public Services $service;

    public Timer $timer;

    public Session $session;

    public string $api_token;
    public Lifecycle $lifecycle;

    public static function env(string $name, $default = null)
    {
        if (array_key_exists($name, $_ENV)) {
            return $_ENV[$name];
        } else {
            return $default;
        }
    }

}

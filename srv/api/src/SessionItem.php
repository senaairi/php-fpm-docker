<?php
namespace Lihzorg\PhpFpmSrvApi;
use Carbon\Carbon;

class SessionItem
{
    public array $data = [];
    public ?Carbon $expires_at;
    /**
     * @var mixed|null
     */

    public function __construct()
    {
        $this->expires_at = Carbon::now()->modify('+2 hour');
    }
    public function expires(): bool
    {
        $now = Carbon::now();
        if ($this->expires_at < $now) {
            return true;
        } else {
            $this->expires_at = $now->modify('+2 hour');
            return false;
        }
    }
    public function __get(string $key)
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        } else {
            return null;
        }
    }
    public function __set(string $key, mixed $value) {
        $this->data[$key] = $value;
    }
}
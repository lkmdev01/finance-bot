<?php

namespace Tests;

use App\Models\Category;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    public User $user;

    public WhatsAppContact $contact;

    public Category $compras;

    /**
     * @param  class-string  $abstract
     */
    public function mock($abstract, ?\Closure $mock = null): MockInterface
    {
        /** @var MockInterface $instance */
        $instance = parent::mock($abstract, $mock);

        return $instance;
    }
}

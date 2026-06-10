<?php

namespace HFlow\LaravelWorkflow\Enums;

enum AuthorizationMode: string
{
    case Public = 'public';
    case Roles = 'roles';
    case Permissions = 'permissions';
    case Users = 'users';
    case Custom = 'custom';
}

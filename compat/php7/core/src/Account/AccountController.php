<?php

declare (strict_types=1);
namespace MuchoCore\Account;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
final class AccountController
{
    public function __construct(AccountService $service)
    {
        $this->service = $service;
    }
    public function register(Request $request): Response
    {
        $result = $this->service->register($request->postString('userName'), $request->postString('password'), $request->postString('email'), $request->clientIp());
        return Response::text($result);
    }
    public function login(Request $request): Response
    {
        $result = $this->service->login($request->postString('userName'), $request->postString('password'), $request->postString('gjp2'), $request->clientIp(), $request->postString('udid'));
        return Response::text($result);
    }
    private $service;
}

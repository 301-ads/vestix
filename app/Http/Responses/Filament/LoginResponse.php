<?php

namespace App\Http\Responses\Filament;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as Responsable;

class LoginResponse extends AuthRedirectResponse implements Responsable {}

<?php

namespace App\Http\Responses\Filament;

use Filament\Auth\Http\Responses\Contracts\RegistrationResponse as Responsable;

class RegistrationResponse extends AuthRedirectResponse implements Responsable {}

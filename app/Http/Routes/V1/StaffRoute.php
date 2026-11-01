<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class StaffRoute
{
    public function map(Registrar $router)
    {
        // Disabled with upstream 3cb9902. Staff authorization is incomplete;
        // do not register these routes until a scoped permission model exists.
    }
}

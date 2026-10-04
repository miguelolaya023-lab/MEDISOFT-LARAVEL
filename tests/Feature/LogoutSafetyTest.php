<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\User;
use RuntimeException;
use Tests\TestCase;

class LogoutSafetyTest extends TestCase
{
    public function test_logout_invalidates_session_even_if_audit_fails(): void
    {
        $user = new User;
        $user->setRawAttributes(['id' => 1, 'name' => 'Usuario', 'estado' => 'activo', 'tipo_usuario' => 'interno', 'rol_id' => 1]);
        Auditoria::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
        try {
            $this->actingAs($user)->withSession(['dato' => 'sesión previa'])->post('/logout')->assertRedirect('/login')->assertSessionMissing('dato');
            $this->assertGuest();
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    public function test_expired_session_can_logout_without_error(): void
    {
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout_cannot_use_get(): void
    {
        $this->get('/logout')->assertMethodNotAllowed();
    }
}

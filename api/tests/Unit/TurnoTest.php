<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Turno;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Horarios de turno, sin base de datos.
 *
 * La razon de que estas pruebas existan: un turno que cruza la medianoche es
 * el caso en el que un modelo con `hora_inicio` y `hora_fin` da la respuesta
 * equivocada sin avisar. El fallo aparece a la una de la manana, con un mesero
 * en el local y una mesa esperando la cuenta.
 */
class TurnoTest extends TestCase
{
    #[Test]
    public function un_turno_normal_contiene_su_horario(): void
    {
        $turno = $this->turno('12:00:00', '17:00:00');

        $this->assertTrue($turno->contiene(CarbonImmutable::parse('2026-10-17 13:30:00')));
        $this->assertTrue($turno->contiene(CarbonImmutable::parse('2026-10-17 12:00:00')));
        $this->assertTrue($turno->contiene(CarbonImmutable::parse('2026-10-17 17:00:00')));

        $this->assertFalse($turno->contiene(CarbonImmutable::parse('2026-10-17 11:59:00')));
        $this->assertFalse($turno->contiene(CarbonImmutable::parse('2026-10-17 17:01:00')));
    }

    #[Test]
    public function un_turno_que_cruza_la_medianoche_incluye_la_una_de_la_manana(): void
    {
        // Con la comparacion ingenua (hora >= inicio && hora <= fin), la una de
        // la manana queda FUERA del turno y el mesero de madrugada no puede
        // cobrar. Este es el caso que justifica el metodo `contiene`.
        $turno = $this->turno('20:00:00', '02:30:00', cruzaMedianoche: true);

        $this->assertTrue($turno->contiene(CarbonImmutable::parse('2026-10-18 01:00:00')));
        $this->assertTrue($turno->contiene(CarbonImmutable::parse('2026-10-17 23:59:00')));
        $this->assertTrue($turno->contiene(CarbonImmutable::parse('2026-10-18 02:30:00')));

        $this->assertFalse($turno->contiene(CarbonImmutable::parse('2026-10-18 03:00:00')));
        $this->assertFalse($turno->contiene(CarbonImmutable::parse('2026-10-17 19:00:00')));
    }

    #[Test]
    public function un_turno_inactivo_nunca_contiene_a_nadie(): void
    {
        $turno = $this->turno('12:00:00', '17:00:00');
        $turno->activo = false;

        $this->assertFalse($turno->contiene(CarbonImmutable::parse('2026-10-17 13:00:00')));
    }

    private function turno(string $inicio, string $fin, bool $cruzaMedianoche = false): Turno
    {
        $turno = new Turno();

        $turno->forceFill([
            'hora_inicio' => $inicio,
            'hora_fin' => $fin,
            'cruza_medianoche' => $cruzaMedianoche,
            'activo' => true,
        ]);

        return $turno;
    }
}
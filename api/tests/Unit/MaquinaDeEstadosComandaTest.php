<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoComanda;
use App\Enums\EstadoItemComanda;
use App\Services\Comandas\MaquinaDeEstadosComanda;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Maquina de estados, sin base de datos.
 *
 * Son las reglas que no pueden depender de que un endpoint se acuerde. Si estas
 * transiciones cambian, hay que cambiar las pruebas, y esa es la idea: que el
 * cambio sea visible.
 */
class MaquinaDeEstadosComandaTest extends TestCase
{
    private MaquinaDeEstadosComanda $maquina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maquina = new MaquinaDeEstadosComanda();
    }

    #[Test]
    public function un_item_pendiente_se_puede_enviar_o_anular(): void
    {
        $this->maquina->moverItem(EstadoItemComanda::Pendiente, EstadoItemComanda::Enviado);
        $this->maquina->moverItem(EstadoItemComanda::Pendiente, EstadoItemComanda::Anulado);

        $this->expectException(DomainException::class);
        $this->maquina->moverItem(EstadoItemComanda::Pendiente, EstadoItemComanda::Listo);
    }

    #[Test]
    public function se_puede_marcar_listo_sin_pasar_por_en_preparacion(): void
    {
        // Decision deliberada: en un local real el cocinero marca el
        // terminado y el sistema marca "en preparacion" cuando alguien toma el
        // pedido. Entre las dos cosas hay un boton, no dos. Exigir las dos
        // transiciones hace que la gente apriete mal y se pierda el dato.
        $this->maquina->moverItem(EstadoItemComanda::Enviado, EstadoItemComanda::Listo);
        $this->maquina->moverItem(EstadoItemComanda::EnPreparacion, EstadoItemComanda::Listo);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function un_item_listo_no_se_puede_anular_porque_ya_esta_emplatado(): void
    {
        // La razon es de caja, no de cocina: un plato en el pasillo que alguien
        // cobre y otro devuelva es peor que cualquier error de captura.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Un item listo ya no se anula');

        $this->maquina->moverItem(EstadoItemComanda::Listo, EstadoItemComanda::Anulado);
    }

    #[Test]
    public function un_item_entregado_es_terminal(): void
    {
        foreach ([EstadoItemComanda::Enviado, EstadoItemComanda::EnPreparacion, EstadoItemComanda::Listo, EstadoItemComanda::Anulado] as $destino) {
            try {
                $this->maquina->moverItem(EstadoItemComanda::Entregado, $destino);

                $this->fail('Un item entregado deberia poder pasar a ' . $destino->value);
            } catch (DomainException) {
                // Correcto: no hay salida desde entregado.
            }
        }

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function una_comanda_abierta_puede_pedir_cuenta_o_anular(): void
    {
        $this->maquina->moverComanda(EstadoComanda::Abierta, EstadoComanda::PorCobrar);
        $this->maquina->moverComanda(EstadoComanda::Abierta, EstadoComanda::Anulada);

        $this->expectException(DomainException::class);
        $this->maquina->moverComanda(EstadoComanda::Abierta, EstadoComanda::Cobrada);
    }

    #[Test]
    public function una_comanda_por_cobrar_puede_reabrirse_porque_el_comensal_pide_una_mas(): void
    {
        $this->maquina->moverComanda(EstadoComanda::PorCobrar, EstadoComanda::Abierta);
        $this->maquina->moverComanda(EstadoComanda::PorCobrar, EstadoComanda::Cobrada);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function una_comanda_cobrada_no_se_puede_mover_de_ninguna_forma(): void
    {
        // Este es el corazon de la regla. No hay excepcion por rol: el estado
        // no admite ninguna salida.
        foreach ([EstadoComanda::Abierta, EstadoComanda::PorCobrar, EstadoComanda::Cobrada, EstadoComanda::Anulada] as $destino) {
            try {
                $this->maquina->moverComanda(EstadoComanda::Cobrada, $destino);

                $this->fail('Una comanda cobrada no deberia poder pasar a ' . $destino->value);
            } catch (DomainException) {
                // Correcto.
            }
        }

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[DataProvider('transicionesPermitidas')]
    public function la_maquina_solo_admite_las_transiciones_declaradas(string $desde, string $hacia): void
    {
        $this->maquina->moverItem(EstadoItemComanda::from($desde), EstadoItemComanda::from($hacia));

        $this->expectNotToPerformAssertions();
    }

    /** @return array<string, array{string, string}> */
    public static function transicionesPermitidas(): array
    {
        return [
            'pendiente a enviado' => ['pendiente', 'enviado'],
            'pendiente a anulado' => ['pendiente', 'anulado'],
            'enviado a en preparacion' => ['enviado', 'en_preparacion'],
            'enviado a listo' => ['enviado', 'listo'],
            'en preparacion a listo' => ['en_preparacion', 'listo'],
            'listo a entregado' => ['listo', 'entregado'],
        ];
    }

    #[Test]
    public function el_mensaje_de_error_explica_que_hacer_y_no_dice_que_rol_fallo(): void
    {
        try {
            $this->maquina->moverComanda(EstadoComanda::Cobrada, EstadoComanda::Anulada);

            $this->fail('La transicion deberia estar prohibida.');
        } catch (DomainException $error) {
            $mensaje = $error->getMessage();

            $this->assertStringContainsString('cobrada', $mensaje);
            $this->assertStringContainsString('punto de venta', $mensaje);
        }
    }
}
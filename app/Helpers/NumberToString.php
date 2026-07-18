<?php

namespace App\Helpers;

class NumberToString
{
    public static function convertir($numero)
    {
        $numero = intval($numero);
        
        $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

        if ($numero == 0) return 'CERO';

        $partes = [];
        $resto = $numero;

        $miles = intval($resto / 1000);
        $resto = $resto % 1000;
        if ($miles > 0) {
            $partes[] = $miles == 1 ? 'MIL' : $unidades[$miles] . ' MIL';
        }

        $centena = intval($resto / 100);
        $resto = $resto % 100;
        if ($centena > 0) {
            if ($centena == 1 && $resto == 0) {
                $partes[] = 'CIEN';
            } else {
                $partes[] = $centenas[$centena];
            }
        }

        $decena = intval($resto / 10);
        $unidad = $resto % 10;

        if ($decena > 0) {
            if ($decena == 1 && $unidad > 0) {
                $partes[] = $unidad == 1 ? 'ONCE' : 
                           ($unidad == 2 ? 'DOCE' : 
                           ($unidad == 3 ? 'TRECE' : 
                           ($unidad == 4 ? 'CATORCE' : 
                           ($unidad == 5 ? 'QUINCE' : 
                           ($unidad == 6 ? 'DIECISÉIS' : 
                           ($unidad == 7 ? 'DIECISIETE' : 
                           ($unidad == 8 ? 'DIECIOCHO' : 'DIECINUEVE')))))));
            } else if ($decena == 2 && $unidad > 0) {
                $partes[] = 'VEINTI' . strtolower($unidades[$unidad]);
            } else {
                $partes[] = $decenas[$decena];
                if ($unidad > 0) {
                    $partes[] = 'Y ' . strtolower($unidades[$unidad]);
                }
            }
        } else if ($unidad > 0) {
            $partes[] = $unidades[$unidad];
        }

        return implode(' ', $partes) . ' BOLIVIANOS';
    }
}
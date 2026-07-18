<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Pago</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            padding: 5mm;
            background: white;
        }

        .label .value{
            font-size: 10px;
            padding-bottom: 7px;
        }
        .recibo{
            padding: 10px ; 
            border: 2px solid black;
        }
        .firmas{
            font-size: 10px;
            text-align: center;
        }
    </style>
</head>
<body>
    <table>
        <tr>
            <td class="recibo">
                <table style="width:100%;">
                    <tr>
                        <td style="width:35%; vertical-align:top; text-align:left;">
                            <p style="font-size:4px; margin:0;">
                                INSTITUTO TECNOLOGICO DE EDUCACION SUPERIOR
                            </p>
                            <img src="{{ public_path('images/logo.png') }}"
                                style="height:30px;">
                        </td>

                        <td style="width:65%; vertical-align:top; text-align:right;">
                            <small style="font-size:6px; text-transform:uppercase; line-height:1.5;">
                                R.M. No. 0000018 - 02804-65602 PADRON NAL. No. 26/0003<br>
                                AFILIADO A "ANDINACEP" "FAPELIA"<br>
                                MIEMBRO DE LA "CORPORACION EDUCATIVA BOLIVIANA"<br>
                                Calle La Paz Nº 524 entre esq. Paje Tupiza<br>
                                Tel. Fax: (32) 5279382 - Oruro-Bolivia
                            </small>
                        </td>
                    </tr>
                </table>
                <table style="padding: 30px 20px; width:100%;">
                    <tr>
                        <td style="width:50%; text-align:center; font-size:18px; font-weight:bold;">
                            RECIBO
                        </td>

                        <td style="width:50%; text-align:right; font-size:15px; font-weight:bold;">
                            Nº: {{ str_pad($pay->id, 6, '0', STR_PAD_LEFT) }} <br>
                            Bs. {{ number_format($pay->amount - $pay->discount, 0) }}
                        </td>

                    </tr>
                </table>
                <table>
                    <tr>
                        <td><span class="label">RECIBO DEL SR.(A):</span></td>
                        <td><span class="value" >
                                {{ $pay->student->user->name }} {{ $pay->student->user->first_lastname }} {{ $pay->student->user->second_lastname }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">LA SUMA DE:</span></td>
                        <td>
                            <span class="value" >
                                {{ $numeroLetras }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">POR CONCEPTO DE:</span></td>
                        <td>
                            <span class="value" >
                                {{ strtoupper($pay->concept->type ?? '...') }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">CARRERA:</span></td>
                        <td>
                            <span class="value" >{{ strtoupper($pay->concept->career->name ?? '...') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">TURNO:</span></td>
                        <td>
                            <span class="value">{{ strtoupper($pay->concept->turno ?? '...') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">GESTION:</span></td>
                        <td>
                            <span class="value" >{{ strtoupper($pay->concept->gestion ?? 'ICER. SEM.') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">MENSUALIDAD Nº:</span></td>
                        <td><span class="value" >{{ strtoupper($pay->description) }}</span></td>
                    </tr>
                    <tr>
                        <td><span class="label">FECHA:</span></td>
                        <td>
                            <span class="value" >
                                ORURO, {{ \Carbon\Carbon::parse($pay->created_at)->format('d-m-Y') }}
                            </span>
                        </td>
                    </tr>
                </table>

                <!-- Firmas-->
                <table style="margin-top: 60px; margin-bottom: 20px; width: 100%;" >
                    <tr class="firmas" >
                        <td>
                            PAGADOR <br>
                            {{ $pay->student->user->name }} {{ $pay->student->user->first_lastname }}
                            {{ $pay->student->user->second_lastname }} 
                        </td>
                        <td>
                            RECIBI CONFORME <br>
                            {{ $pay->casher->name ?? '...' }} {{ $pay->casher->first_lastname ?? '' }}
                            {{ $pay->casher->second_lastname ?? '' }}

                        </td>
                    </tr>
                </table>
            </td>

             <td class="recibo">
                <table style="width:100%;">
                    <tr>
                        <td style="width:35%; vertical-align:top; text-align:left;">
                            <p style="font-size:4px; margin:0;">
                                INSTITUTO TECNOLOGICO DE EDUCACION SUPERIOR
                            </p>
                            <img src="{{ public_path('images/logo.png') }}"
                                style="height:30px;">
                        </td>

                        <td style="width:65%; vertical-align:top; text-align:right;">
                            <small style="font-size:6px; text-transform:uppercase; line-height:1.5;">
                                R.M. No. 0000018 - 02804-65602 PADRON NAL. No. 26/0003<br>
                                AFILIADO A "ANDINACEP" "FAPELIA"<br>
                                MIEMBRO DE LA "CORPORACION EDUCATIVA BOLIVIANA"<br>
                                Calle La Paz Nº 524 entre esq. Paje Tupiza<br>
                                Tel. Fax: (32) 5279382 - Oruro-Bolivia
                            </small>
                        </td>
                    </tr>
                </table>
                <table style="padding: 30px 20px; width:100%;">
                    <tr>
                        <td style="width:50%; text-align:center; font-size:18px; font-weight:bold;">
                            RECIBO
                        </td>

                        <td style="width:50%; text-align:right; font-size:15px; font-weight:bold;">
                            Nº: {{ str_pad($pay->id, 6, '0', STR_PAD_LEFT) }} <br>
                            Bs. {{ number_format($pay->amount - $pay->discount, 0) }}
                        </td>

                    </tr>
                </table>
                <table>
                    <tr>
                        <td><span class="label">RECIBO DEL SR.(A):</span></td>
                        <td><span class="value" >
                                {{ $pay->student->user->name }} {{ $pay->student->user->first_lastname }} {{ $pay->student->user->second_lastname }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">LA SUMA DE:</span></td>
                        <td>
                            <span class="value" >
                                {{ $numeroLetras }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">POR CONCEPTO DE:</span></td>
                        <td>
                            <span class="value" >
                                {{ strtoupper($pay->concept->type ?? '...') }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">CARRERA:</span></td>
                        <td>
                            <span class="value" >{{ strtoupper($pay->concept->career->name ?? '...') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">TURNO:</span></td>
                        <td>
                            <span class="value">{{ strtoupper($pay->concept->turno ?? '...') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">GESTION:</span></td>
                        <td>
                            <span class="value" >{{ strtoupper($pay->concept->gestion ?? 'ICER. SEM.') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="label">MENSUALIDAD Nº:</span></td>
                        <td><span class="value" >{{ strtoupper($pay->description) }}</span></td>
                    </tr>
                    <tr>
                        <td><span class="label">FECHA:</span></td>
                        <td>
                            <span class="value" >
                                ORURO, {{ \Carbon\Carbon::parse($pay->created_at)->format('d-m-Y') }}
                            </span>
                        </td>
                    </tr>
                </table>

                <!-- Firmas-->
                <table style="margin-top: 60px; margin-bottom: 20px; width: 100%;" >
                    <tr class="firmas" >
                        <td>
                            PAGADOR <br>
                            {{ $pay->student->user->name }} {{ $pay->student->user->first_lastname }}
                            {{ $pay->student->user->second_lastname }} 
                        </td>
                        <td>
                            RECIBI CONFORME <br>
                            {{ $pay->casher->name ?? '...' }} {{ $pay->casher->first_lastname ?? '' }}
                            {{ $pay->casher->second_lastname ?? '' }}

                        </td>
                    </tr>
                </table>
            </td>           
        </tr>
    </table>

</body>
</html>
<?php

declare(strict_types=1);

/*
|-------------------------------------------------------------------------------
| Rutas de consola
|-------------------------------------------------------------------------------
|
| Los comandos de Peluqueria se descubren automáticamente desde
| app/Console/Commands. Se dejan declarados aquí para que quede explícito que no
| hay comandos programados: las tareas periódicas que hacen falta (recálculo de
| comisiones, revisión de alertas) son del despliegue, y deben declararse a
| mano. Ver docs/08.
|
| EL QUE SÍ IMPORTA
| -----------------
| `peluqueria:verificar-aislamiento` comprueba, desde el servidor y sin pasar
| por la aplicación, que las dieciséis tablas de negocio tienen RLS forzado, que
| la vista pública expone solo tres columnas, y que una consulta sin salón
| falla. Es el comando que hay que correr antes de recibir a la primera clienta.
| Ver docs/03.
|
| `peluqueria:crear-salon` da de alta un salón con su primer administrador, y
| es la única excepción controlada al aislamiento. Ver docs/04.
|
*/
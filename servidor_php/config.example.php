<?php
// Plantilla de configuración. Copiar como config.php y completar. config.php NO se sube al repositorio.
return [
    // Se prueba cada servidor en orden hasta que uno conecte.
    'db_hosts'   => ['localhost'],
    'db_name'    => 'NOMBRE_DE_LA_BASE',
    'db_user'    => 'USUARIO_DE_LA_BASE',
    'db_pass'    => 'CLAVE_DE_LA_BASE',

    // La misma clave va en "api_key" del config.json de la PC local.
    'api_key'    => 'CLAVE_LARGA_ALEATORIA',

    // Acceso al dashboard (panel.php).
    'panel_user' => 'USUARIO_DEL_PANEL',
    'panel_pass' => 'CLAVE_DEL_PANEL',
];

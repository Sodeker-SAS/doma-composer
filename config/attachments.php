<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento
    |--------------------------------------------------------------------------
    |
    | Disco de `config/filesystems.php` donde se escriben los adjuntos. Hoy es el
    | disco local `public`; cuando el bucket esté listo, pasar a S3 es instalar
    | `league/flysystem-aws-s3-v3`, llenar las variables AWS_* y cambiar esta
    | variable a `s3`. Ni el dominio ni los módulos consumidores se enteran: el
    | disco solo lo conoce el adaptador de Infrastructure.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Documentos privados · LÍMITE CONOCIDO Y ACEPTADO POR AHORA
    |--------------------------------------------------------------------------
    |
    | El módulo NO distingue entre adjuntos públicos y privados: escribe todo en
    | el disco resuelto para el cliente, sea cual sea. La marca `is_private` vive
    | en la pivote del módulo consumidor (`fin_study_attachments.is_private`) y hoy
    | solo tiene un efecto: la lectura omite el enlace cuando está activa.
    |
    | CONSECUENCIA, mientras el disco por defecto sea `public`: el objeto queda
    | bajo `storage/app/public`, que está enlazado a `public/storage` y lo sirve
    | nginx sin pasar por Laravel. Es decir, un documento marcado como privado
    | SIGUE SIENDO ALCANZABLE por cualquiera que tenga la URL. Lo único que lo
    | protege es que el nombre físico es un ULID y no se publica en ninguna
    | respuesta cuando el adjunto es privado — o sea, es difícil de adivinar, no
    | inaccesible. Los dos caminos del ERP marcan `isPrivate: true` siempre, así
    | que esto aplica a todos los documentos que entran por ahí.
    |
    | POR QUÉ SE DEJA ASÍ: hoy los documentos viven en el disco local de un
    | despliegue al que solo llegan los propios clientes, y resolverlo bien no es
    | mover archivos de sitio sino construir el camino de lectura del módulo
    | —enlaces firmados con vencimiento y control de acceso—, que es trabajo
    | aparte y todavía no toca.
    |
    | QUÉ HAY QUE HACER CUANDO TOQUE, en este orden:
    |
    |   1. Que la privacidad entre al módulo: `AttachmentStoragePort::store()`
    |      debe recibirla (o `AttachmentOwner` llevarla), porque quien elige el
    |      disco y la visibilidad es el módulo, no el consumidor.
    |   2. Un disco no público para lo privado: `local` en lugar de `public`, o un
    |      bucket con `visibility => 'private'`.
    |   3. Camino de lectura propio del módulo, con URL firmada y vencimiento, en
    |      lugar del `Storage::disk()->url()` que hace hoy el consumidor en
    |      `ResolvesAttachmentUrls`.
    |
    | Mientras eso no exista: NO pongas `ATTACHMENTS_DISK=s3` con un bucket de
    | lectura pública, porque entonces sí quedarían expuestos fuera de la red del
    | despliegue.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Prefijo de aplicación
    |--------------------------------------------------------------------------
    |
    | Primer segmento de la clave de todo adjunto. Cada aplicación declara el suyo
    | para poder compartir almacenamiento con el resto del ecosistema sin colisiones
    | ni reorganizaciones posteriores.
    |
    */

    'app_prefix' => env('ATTACHMENTS_APP_PREFIX', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Persistencia del registro de adjuntos
    |--------------------------------------------------------------------------
    |
    | La conexión y la tabla son configurables porque cada aplicación declara el
    | prefijo de su esquema (por ejemplo, `fin_attachments` o `sat_attachments`).
    | Cambiar la tabla en una aplicación que ya tiene datos deja los adjuntos
    | históricos inalcanzables hasta que esos registros se migren o se restaure
    | el nombre anterior.
    |
    */

    'table' => env('ATTACHMENTS_TABLE', 'attachments'),

    'connection' => env('ATTACHMENTS_CONNECTION', 'tenant'),

    /*
    |--------------------------------------------------------------------------
    | Límite de tamaño por archivo
    |--------------------------------------------------------------------------
    |
    | OJO: nginx corta el CUERPO COMPLETO de la petición en 64 MB
    | (`docker/nginx/default.conf`). Si se permiten varios archivos por llamada,
    | la suma también debe caber ahí o el 413 lo devuelve nginx antes de que
    | Laravel llegue a validar nada.
    |
    */

    'max_size_bytes' => 52428800, // 50 MB

    /*
    |--------------------------------------------------------------------------
    | Tipos permitidos
    |--------------------------------------------------------------------------
    |
    | La clave es la extensión canónica y el valor su firma binaria (magic bytes).
    | La validación NO confía en la extensión del nombre ni en el Content-Type que
    | envía el cliente: lee los primeros bytes del archivo y exige que coincidan.
    | Así un ejecutable renombrado a .pdf se rechaza antes de tocar el disco.
    |
    | OJO: docx, xlsx y pptx COMPARTEN firma. Los tres son OOXML, que por dentro es
    | un ZIP, así que los tres empiezan por "PK\x03\x04" y los magic bytes por sí
    | solos no los distinguen. Quién es quién lo decide `container_markers`.
    |
    */

    'allowed_types' => [
        'pdf' => '%PDF',
        'jpg' => "\xFF\xD8\xFF",
        'png' => "\x89PNG\r\n\x1A\n",
        // Contenedor OLE2 del Office antiguo. Ver la nota de más abajo.
        'xls' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1",
        // Familia OOXML: misma firma los tres, se separan por su marcador.
        'docx' => "PK\x03\x04",
        'xlsx' => "PK\x03\x04",
        'pptx' => "PK\x03\x04",
    ],

    /*
    |--------------------------------------------------------------------------
    | Desambiguación de formatos contenedor
    |--------------------------------------------------------------------------
    |
    | Cuando varios formatos comparten firma, los magic bytes solo dicen «esto es
    | un contenedor». Para saber cuál es hay que mirar qué lleva dentro: un OOXML
    | guarda sus partes en carpetas, y el nombre de esas entradas viaja SIN
    | comprimir en las cabeceras del ZIP, así que basta con buscarlo en la
    | cabecera del archivo — no hace falta descomprimir nada.
    |
    | Esto también cierra el agujero contrario: un .zip cualquiera renombrado a
    | .xlsx no trae ninguno de estos marcadores, así que se rechaza en vez de
    | guardarse como si fuera un libro de Excel.
    |
    | REGLA AL AÑADIR FORMATOS: si declaras dos extensiones con la misma firma en
    | `allowed_types`, AMBAS necesitan marcador aquí. Sin él nunca se reconocen.
    |
    | LÍMITE CONOCIDO · el Office antiguo. `.xls` es un contenedor OLE2, igual que
    | `.doc` y `.ppt`. Como `xls` es hoy el único formato OLE2 de la lista, no hace
    | falta desambiguarlo y se acepta cualquier OLE2 como xls — un `.doc`
    | renombrado a `.xls` entraría. Separarlos exige recorrer el directorio interno
    | del contenedor, que es bastante más trabajo que buscar una cadena, y no se
    | hizo porque hoy no se aceptan ni `.doc` ni `.ppt`.
    |
    */

    'container_markers' => [
        'docx' => 'word/',
        'xlsx' => 'xl/',
        'pptx' => 'ppt/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Formatos que admiten la cabecera desplazada
    |--------------------------------------------------------------------------
    |
    | Por defecto la firma tiene que estar en el byte CERO. Para el PDF eso es
    | más estricto de lo que el propio formato exige: la especificación admite
    | que la cabecera no esté al principio, y los lectores de verdad la buscan
    | dentro del primer kilobyte. En la práctica aparecen PDFs perfectamente
    | válidos con un BOM, un salto de línea o unos espacios delante, porque han
    | pasado por un firmador, un conversor o un export descuidado.
    |
    | El valor son los bytes iniciales dentro de los cuales se acepta encontrar
    | la firma. Solo se consulta cuando NINGÚN formato ha encajado por el byte
    | cero, así que un archivo que sí es lo que dice nunca pasa por aquí.
    |
    | NO LO EXTIENDAS SIN MOTIVO: para png, jpg o los contenedores, la firma es
    | el primer byte por definición. Buscarla en vez de exigirla permitiría
    | anteponer contenido arbitrario a una cabecera válida, que es justo lo que
    | esta comprobación existe para impedir.
    |
    */

    'signature_search_bytes' => [
        'pdf' => 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alias de extensión
    |--------------------------------------------------------------------------
    |
    | Nombres alternativos que el consumidor puede enviar y que se normalizan a la
    | extensión canónica de `allowed_types`.
    |
    */

    'extension_aliases' => [
        'jpeg' => 'jpg',
    ],

    /*
    |--------------------------------------------------------------------------
    | Declaraciones equivalentes
    |--------------------------------------------------------------------------
    |
    | Extensiones que comparten formato interno con un tipo verificado y pueden
    | declararse en su lugar SIN que se considere una discrepancia. El archivo se
    | verifica contra la firma del tipo detectado, pero se guarda con la extensión
    | declarada.
    |
    | POR QUÉ HACE FALTA PARA `xlsm`: un Excel con macros es OOXML igual que un
    | xlsx —misma firma `PK\x03\x04` y mismo marcador `xl/`—, así que los magic
    | bytes lo detectan como `xlsx`. Lo que de verdad los separa es la entrada
    | `xl/vbaProject.bin`, que está demasiado adentro del archivo para verla en los
    | primeros bytes. Declararlo aquí conserva la verificación de que es un OOXML
    | válido y a la vez respeta la extensión que el usuario envió.
    |
    | NO ES UN ALIAS: `extension_aliases` NORMALIZA (un jpeg se guarda como jpg).
    | Esto CONSERVA la extensión declarada, que es lo correcto cuando el archivo
    | realmente es de ese subtipo.
    |
    */

    'equivalent_declarations' => [
        'xlsx' => ['xlsm'],
        'docx' => ['docm'],
        'pptx' => ['pptm'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos sin firma binaria
    |--------------------------------------------------------------------------
    |
    | Formatos que se aceptan por su extensión porque NO TIENEN una firma contra
    | la que contrastarlos: son bytes arbitrarios. Un `.log` o un `.txt` pueden
    | contener cualquier cosa y no hay nada que verificar.
    |
    | QUÉ SE PIERDE, y hay que aceptarlo conscientemente: para estos tipos no se
    | puede detectar un archivo renombrado. Un ejecutable llamado `notas.txt` se
    | acepta. Por eso la defensa real no es esta lista, sino el camino de lectura:
    | servirlos siempre como descarga y nunca en línea desde el dominio de la
    | aplicación.
    |
    | Solo se consultan cuando NINGUNA firma encajó, así que un archivo que sí es
    | un formato conocido nunca llega aquí.
    |
    */

    'opaque_types' => [
        'txt', 'log', 'md', 'csv', 'json', 'xml', 'dump', 'sql', 'yml', 'yaml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensiones bloqueadas · NO SE RELAJAN POR MÓDULO
    |--------------------------------------------------------------------------
    |
    | Se rechazan SIEMPRE, antes de mirar el contenido y sin importar lo que pida
    | el módulo consumidor. No están aquí por política de negocio sino porque son
    | peligrosas AL SERVIRSE: si el navegador las interpreta desde el dominio de la
    | aplicación, el archivo deja de ser un dato y pasa a ser código ejecutándose
    | con la sesión de quien lo abre.
    |
    | Un módulo puede decidir que no quiere imágenes; NO puede decidir que sí
    | quiere `.html`. Esa es la frontera entre política y seguridad.
    |
    */

    'blocked_extensions' => [
        'svg', 'html', 'htm', 'xhtml', 'shtml',
        'php', 'phtml', 'phar', 'js', 'mjs', 'jsp', 'asp', 'aspx',
    ],

];

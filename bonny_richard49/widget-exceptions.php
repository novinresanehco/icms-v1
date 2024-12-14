// app/Core/Widget/Exceptions/WidgetException.php
<?php

namespace App\Core\Widget\Exceptions;

class WidgetException extends \Exception 
{
}

// app/Core/Widget/Exceptions/WidgetCreationException.php
<?php

namespace App\Core\Widget\Exceptions;

class WidgetCreationException extends WidgetException 
{
}

// app/Core/Widget/Exceptions/UnknownWidgetTypeException.php
<?php 

namespace App\Core\Widget\Exceptions;

class UnknownWidgetTypeException extends WidgetException
{
}

// app/Core/Widget/Exceptions/WidgetRenderException.php
<?php

namespace App\Core\Widget\Exceptions;

class WidgetRenderException extends WidgetException
{
}
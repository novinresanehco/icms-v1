<?php

namespace App\Modules\Exceptions;

class ModuleException extends \Exception {}

class ModuleNotFoundException extends ModuleException {}

class ModuleDependencyException extends ModuleException {}

class ModuleInstallationException extends ModuleException {}

class ModuleConfigurationException extends ModuleException {}

class ModuleValidationException extends ModuleException {}

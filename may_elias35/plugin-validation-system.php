// File: app/Core/Plugin/Validation/PluginValidator.php
<?php

namespace App\Core\Plugin\Validation;

class PluginValidator
{
    protected StructureValidator $structureValidator;
    protected DependencyResolver $dependencyResolver;
    protected RequirementChecker $requirementChecker;

    public function validate(Plugin $plugin): bool
    {
        if (!$this->structureValidator->validate($plugin)) {
            throw new ValidationException("Invalid plugin structure");
        }

        if (!$this->validateRequirements($plugin)) {
            throw new ValidationException("System requirements not met");
        }

        try {
            $this->dependencyResolver->resolve($plugin);
        } catch (DependencyException $e) {
            throw new ValidationException($e->getMessage());
        }

        return true;
    }

    public function canActivate(Plugin $plugin): bool
    {
        if (!$plugin->isInstalled()) {
            return false;
        }

        try {
            $dependencies = $this->dependencyResolver->resolve($plugin);
            foreach ($dependencies as $dependency) {
                if (!$dependency->isActive()) {
                    return false;
                }
            }
        } catch (DependencyException $e) {
            return false;
        }

        return true;
    }

    protected function validateRequirements(Plugin $plugin): bool
    {
        return $this->requirementChecker->check($plugin->getRequirements());
    }
}

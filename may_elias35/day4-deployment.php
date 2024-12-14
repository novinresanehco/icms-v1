<?php
namespace App\Core\Deployment;

class DeploymentManager implements CriticalDeploymentInterface
{
    private SecurityCore $security;
    private ValidationService $validator;
    private MonitoringManager $monitor;
    private BackupManager $backup;

    public function deploy(): DeploymentResult
    {
        return $this->security->validateOperation(
            new DeploymentOperation(
                $this->validator,
                $this->monitor,
                $this->backup
            )
        );
    }

    public function rollback(): void
    {
        $this->security->validateOperation(
            new RollbackOperation(
                $this->backup,
                $this->monitor
            )
        );
    }

    public function verifyDeployment(): void
    {
        $this->security->validateOperation(
            new VerifyDeploymentOperation(
                $this->validator,
                $this->monitor
            )
        );
    }
}

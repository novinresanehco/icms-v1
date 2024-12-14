<?php

namespace App\Core\Repository\Traits;

use App\Core\Repository\Criteria\CriteriaInterface;
use Illuminate\Support\Collection;
use App\Core\Repository\Exceptions\RepositoryException;

trait HasCriteria
{
    /**
     * @var Collection
     */
    protected Collection $criteria;

    /**
     * @var bool
     */
    protected bool $skipCriteria = false;

    /**
     * Initialize criteria collection
     */
    public function bootHasCriteria(): void
    {
        $this->criteria = new Collection();
    }

    /**
     * Add a criterion
     *
     * @param CriteriaInterface $criteria
     * @return self
     */
    public function pushCriteria(CriteriaInterface $criteria): self
    {
        $this->criteria->push($criteria);
        return $this;
    }

    /**
     * Add multiple criteria
     *
     * @param array $criteria
     * @return self
     */
    public function pushCriterias(array $criteria): self
    {
        foreach ($criteria as $criterion) {
            if (!$criterion instanceof CriteriaInterface) {
                throw new RepositoryException(
                    'Class ' . get_class($criterion) . ' must be an instance of CriteriaInterface'
                );
            }
            $this->pushCriteria($criterion);
        }
        return $this;
    }

    /**
     * Get criteria
     *
     * @return Collection
     */
    public function getCriteria(): Collection
    {
        return $this->criteria;
    }

    /**
     * Remove all criteria
     *
     * @return self
     */
    public function resetCriteria(): self
    {
        $this->criteria = new Collection();
        return $this;
    }

    /**
     * Remove a criterion
     *
     * @param CriteriaInterface $criteria
     * @return self
     */
    public function removeCriteria(CriteriaInterface $criteria): self
    {
        $this->criteria = $this->criteria->reject(function ($item) use ($criteria) {
            return get_class($item) === get_class($criteria);
        });
        return $this;
    }

    /**
     * Skip criteria for the next query
     *
     * @param bool $skip
     * @return self
     */
    public function skipCriteria(bool $skip = true): self
    {
        $this->skipCriteria = $skip;
        return $this;
    }

    /**
     * Apply all criteria to the model
     *
     * @return void
     */
    protected function applyCriteria(): void
    {
        if ($this->skipCriteria) {
            $this->skipCriteria = false;
            return;
        }

        foreach ($this->getCriteria() as $criteria) {
            if ($criteria instanceof CriteriaInterface) {
                $this->model = $criteria->apply($this->model, $this);
            }
        }
    }
}

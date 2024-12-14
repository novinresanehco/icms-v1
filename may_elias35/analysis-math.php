<?php

namespace App\Core\Audit\Math;

class MatrixCalculator
{
    public function multiply(array $a, array $b): array
    {
        $rowsA = count($a);
        $colsA = count($a[0]);
        $colsB = count($b[0]);
        
        $result = array_fill(0, $rowsA, array_fill(0, $colsB, 0));
        
        for ($i = 0; $i < $rowsA; $i++) {
            for ($j = 0; $j < $colsB; $j++) {
                for ($k = 0; $k < $colsA; $k++) {
                    $result[$i][$j] += $a[$i][$k] * $b[$k][$j];
                }
            }
        }
        
        return $result;
    }

    public function transpose(array $matrix): array
    {
        $rows = count($matrix);
        $cols = count($matrix[0]);
        
        $result = array_fill(0, $cols, array_fill(0, $rows, 0));
        
        for ($i = 0; $i < $rows; $i++) {
            for ($j = 0; $j < $cols; $j++) {
                $result[$j][$i] = $matrix[$i][$j];
            }
        }
        
        return $result;
    }

    public function inverse(array $matrix): array
    {
        // Basic 2x2 matrix inverse implementation
        if (count($matrix) !== 2 || count($matrix[0]) !== 2) {
            throw new \InvalidArgumentException('Only 2x2 matrices supported');
        }
        
        $det = $matrix[0][0] * $matrix[1][1] - $matrix[0][1] * $matrix[1][0];
        
        if ($det == 0) {
            throw new \InvalidArgumentException('Matrix is not invertible');
        }
        
        return [
            [$matrix[1][1] / $det, -$matrix[0][1] / $det],
            [-$matrix[1][0] / $det, $matrix[0][0] / $det]
        ];
    }
}

class VectorCalculator
{
    public function dotProduct(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new \InvalidArgumentException('Vectors must be same length');
        }
        
        return array_sum(array_map(
            fn($x, $y) => $x * $y,
            $a,
            $b
        ));
    }

    public function magnitude(array $vector): float
    {
        return sqrt($this->dotProduct($vector, $vector));
    }

    public function normalize(array $vector): array
    {
        $magnitude = $this->magnitude($vector);
        
        if ($magnitude == 0) {
            throw new \InvalidArgumentException('Cannot normalize zero vector');
        }
        
        return array_map(
            fn($x) => $x / $magnitude,
            $vector
        );
    }

    public function angleBetween(array $a, array $b): float
    {
        $dot = $this->dotProduct($a, $b);
        $magA = $this->magnitude($a);
        $magB = $this->magnitude($b);
        
        return acos($dot / ($magA * $magB));
    }
}

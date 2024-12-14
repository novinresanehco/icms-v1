import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Clock, ShieldCheck, Activity, AlertTriangle } from 'lucide-react';

const CriticalControlMatrix = () => {
  const [timeElapsed, setTimeElapsed] = useState(0);
  
  useEffect(() => {
    const timer = setInterval(() => {
      setTimeElapsed(prev => prev + 1);
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  return (
    <div className="w-full max-w-4xl mx-auto space-y-4">
      <Card className="border-2 border-red-500">
        <CardHeader className="bg-red-50">
          <div className="flex justify-between items-center">
            <h2 className="text-lg font-bold">CRITICAL PROJECT CONTROL MATRIX</h2>
            <Badge variant="destructive" className="text-sm">ACTIVE</Badge>
          </div>
        </CardHeader>
        <CardContent className="p-6">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {/* Time Control */}
            <div className="p-4 border rounded-lg bg-orange-50">
              <div className="flex items-center gap-2 mb-2">
                <Clock className="h-4 w-4 text-orange-700" />
                <h3 className="font-semibold text-orange-700">Timeline</h3>
              </div>
              <div className="text-sm">
                <p className="font-mono">Deadline: 3-4 days</p>
                <p className="font-mono">Status: CRITICAL</p>
              </div>
            </div>

            {/* Security Control */}
            <div className="p-4 border rounded-lg bg-blue-50">
              <div className="flex items-center gap-2 mb-2">
                <ShieldCheck className="h-4 w-4 text-blue-700" />
                <h3 className="font-semibold text-blue-700">Security</h3>
              </div>
              <div className="text-sm">
                <p className="font-mono">Auth: ENFORCED</p>
                <p className="font-mono">Audit: ACTIVE</p>
              </div>
            </div>

            {/* Performance Control */}
            <div className="p-4 border rounded-lg bg-green-50">
              <div className="flex items-center gap-2 mb-2">
                <Activity className="h-4 w-4 text-green-700" />
                <h3 className="font-semibold text-green-700">Performance</h3>
              </div>
              <div className="text-sm">
                <p className="font-mono">API: <100ms</p>
                <p className="font-mono">Load: <200ms</p>
              </div>
            </div>

            {/* Risk Control */}
            <div className="p-4 border rounded-lg bg-yellow-50">
              <div className="flex items-center gap-2 mb-2">
                <AlertTriangle className="h-4 w-4 text-yellow-700" />
                <h3 className="font-semibold text-yellow-700">Risk</h3>
              </div>
              <div className="text-sm">
                <p className="font-mono">Tolerance: ZERO</p>
                <p className="font-mono">Monitor: 24/7</p>
              </div>
            </div>
          </div>

          <div className="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            {/* Critical Roles */}
            <div className="border rounded-lg p-4">
              <h3 className="font-semibold mb-2">Critical Roles</h3>
              <ul className="text-sm space-y-2">
                <li className="font-mono">Senior Dev 1: Security Core</li>
                <li className="font-mono">Senior Dev 2: CMS Core</li>
                <li className="font-mono">Dev 3: Infrastructure</li>
              </ul>
            </div>

            {/* Validation Gates */}
            <div className="border rounded-lg p-4">
              <h3 className="font-semibold mb-2">Validation Gates</h3>
              <ul className="text-sm space-y-2">
                <li className="font-mono">Security Scan: REQUIRED</li>
                <li className="font-mono">Code Review: MANDATORY</li>
                <li className="font-mono">Performance Test: ENFORCED</li>
              </ul>
            </div>

            {/* Critical Metrics */}
            <div className="border rounded-lg p-4">
              <h3 className="font-semibold mb-2">Critical Metrics</h3>
              <ul className="text-sm space-y-2">
                <li className="font-mono">Error Rate: ZERO TOLERANCE</li>
                <li className="font-mono">Test Coverage: 100%</li>
                <li className="font-mono">Security: MAX LEVEL</li>
              </ul>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default CriticalControlMatrix;

import React from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Shield, Clock, Activity, AlertTriangle } from 'lucide-react';

export default function CriticalControlMatrix() {
  return (
    <div className="w-full space-y-4">
      <Alert variant="destructive">
        <AlertTriangle className="h-4 w-4" />
        <AlertTitle>CRITICAL CONTROL PROTOCOL ACTIVE</AlertTitle>
      </Alert>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <div className="flex items-center space-x-2">
              <Shield className="h-4 w-4" />
              <h3 className="font-bold">SENIOR DEV 1</h3>
            </div>
            <Badge variant="destructive">SECURITY CORE</Badge>
          </CardHeader>
          <CardContent>
            <ul className="text-sm space-y-2">
              <li>• Authentication System</li>
              <li>• Authorization Framework</li>
              <li>• Encryption Protocols</li>
              <li>• Security Monitoring</li>
            </ul>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <div className="flex items-center space-x-2">
              <Activity className="h-4 w-4" />
              <h3 className="font-bold">SENIOR DEV 2</h3>
            </div>
            <Badge>CMS CORE</Badge>
          </CardHeader>
          <CardContent>
            <ul className="text-sm space-y-2">
              <li>• Content Management</li>
              <li>• Version Control</li>
              <li>• Media Handling</li>
              <li>• API Integration</li>
            </ul>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <div className="flex items-center space-x-2">
              <Clock className="h-4 w-4" />
              <h3 className="font-bold">DEV 3</h3>
            </div>
            <Badge>INFRASTRUCTURE</Badge>
          </CardHeader>
          <CardContent>
            <ul className="text-sm space-y-2">
              <li>• System Stability</li>
              <li>• Performance Tuning</li>
              <li>• Caching Layer</li>
              <li>• Database Optimization</li>
            </ul>
          </CardContent>
        </Card>
      </div>

      <Card className="mt-4">
        <CardHeader>
          <h3 className="font-bold">CRITICAL VALIDATION GATES</h3>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <h4 className="font-bold text-sm mb-2">SECURITY</h4>
              <ul className="text-sm space-y-1">
                <li>• MFA Required</li>
                <li>• Role-based Access</li>
                <li>• AES-256 Encryption</li>
                <li>• Audit Logging</li>
              </ul>
            </div>
            <div>
              <h4 className="font-bold text-sm mb-2">PERFORMANCE</h4>
              <ul className="text-sm space-y-1">
                <li>• Response &lt;100ms</li>
                <li>• Load &lt;200ms</li>
                <li>• CPU &lt;70%</li>
                <li>• Memory &lt;80%</li>
              </ul>
            </div>
            <div>
              <h4 className="font-bold text-sm mb-2">QUALITY</h4>
              <ul className="text-sm space-y-1">
                <li>• 100% Test Coverage</li>
                <li>• Code Review Required</li>
                <li>• Zero Known Vulnerabilities</li>
                <li>• Complete Documentation</li>
              </ul>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

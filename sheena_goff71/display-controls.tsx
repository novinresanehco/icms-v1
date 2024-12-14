import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Alert } from '@/components/ui/alert';
import { Monitor, Database, Shield, Lock } from 'lucide-react';

const DisplaySystem = () => {
  const [displayState, setDisplayState] = useState({
    securityStatus: true,
    validationActive: true,
    renderingStatus: true,
    cacheStatus: true
  });

  const [contentState, setContentState] = useState({
    isProcessing: false,
    isSecure: true,
    isValidated: true,
    isComplete: true
  });

  return (
    <div className="w-full">
      <Card className="w-full">
        <CardContent className="p-6 space-y-4">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <Alert className="flex items-center gap-2">
              <Shield className={`h-4 w-4 ${displayState.securityStatus ? 'text-green-500' : 'text-red-500'}`} />
              <span className="font-medium">Security</span>
            </Alert>

            <Alert className="flex items-center gap-2">
              <Lock className={`h-4 w-4 ${displayState.validationActive ? 'text-green-500' : 'text-red-500'}`} />
              <span className="font-medium">Validation</span>
            </Alert>

            <Alert className="flex items-center gap-2">
              <Monitor className={`h-4 w-4 ${displayState.renderingStatus ? 'text-green-500' : 'text-red-500'}`} />
              <span className="font-medium">Rendering</span>
            </Alert>

            <Alert className="flex items-center gap-2">
              <Database className={`h-4 w-4 ${displayState.cacheStatus ? 'text-green-500' : 'text-red-500'}`} />
              <span className="font-medium">Cache</span>
            </Alert>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {Array.from({ length: 6 }).map((_, index) => (
              <Card key={index}>
                <CardContent className="p-4">
                  <div className="aspect-video bg-gray-50 rounded flex items-center justify-center relative">
                    <Monitor className="h-8 w-8 text-gray-300" />
                    <div className="absolute top-2 right-2">
                      <Shield className="h-3 w-3 text-green-500" />
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default DisplaySystem;

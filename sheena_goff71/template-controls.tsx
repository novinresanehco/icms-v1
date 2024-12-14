import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Alert } from '@/components/ui/alert';
import { RefreshCcw, Lock, Settings } from 'lucide-react';

const TemplateControls = () => {
  const [controlState, setControlState] = useState({
    locked: true,
    secured: true,
    processing: false
  });

  return (
    <div className="w-full max-w-4xl mx-auto">
      <Card>
        <CardContent className="p-4">
          <div className="grid grid-cols-3 gap-4">
            <Alert variant="default" className="flex items-center gap-2">
              <Lock className={`h-4 w-4 ${controlState.secured ? 'text-green-500' : 'text-red-500'}`} />
              <span className="text-sm font-medium">Security Active</span>
            </Alert>

            <Alert variant="default" className="flex items-center gap-2">
              <Settings className={`h-4 w-4 ${controlState.locked ? 'text-green-500' : 'text-red-500'}`} />
              <span className="text-sm font-medium">Controls Locked</span>
            </Alert>

            <Alert variant="default" className="flex items-center gap-2">
              <RefreshCcw className={`h-4 w-4 ${controlState.processing ? 'animate-spin' : ''}`} />
              <span className="text-sm font-medium">Processing</span>
            </Alert>
          </div>

          <div className="mt-4 border rounded-lg p-4 min-h-[100px] flex items-center justify-center">
            <span className="text-gray-500">Template Control Interface</span>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default TemplateControls;

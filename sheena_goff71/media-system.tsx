import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Alert } from '@/components/ui/alert';
import { Image, Lock, Shield, CheckCircle } from 'lucide-react';

const MediaSystem = () => {
  const [mediaState, setMediaState] = useState({
    secured: true,
    validated: true,
    processing: false,
    loaded: true
  });

  const MediaGrid = () => (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
      {Array.from({ length: 8 }).map((_, index) => (
        <Card key={index} className="relative overflow-hidden">
          <CardContent className="p-2">
            <div className="aspect-square bg-gray-50 rounded flex items-center justify-center">
              <Image className="h-6 w-6 text-gray-300" />
            </div>
            <div className="absolute top-2 right-2">
              <Shield className="h-3 w-3 text-green-500" />
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  );

  return (
    <div className="w-full space-y-4">
      <Card>
        <CardContent className="p-6">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <Lock className="h-5 w-5" />
              <h3 className="font-semibold">Secure Media System</h3>
            </div>
            <div className="flex gap-2">
              {Object.entries(mediaState).map(([key, value]) => (
                <CheckCircle 
                  key={key}
                  className={`h-4 w-4 ${value ? 'text-green-500' : 'text-red-500'}`} 
                />
              ))}
            </div>
          </div>
          
          <Alert className="mb-4">
            <Shield className="h-4 w-4" />
            <span className="ml-2">Protected media processing active</span>
          </Alert>

          <MediaGrid />
        </CardContent>
      </Card>
    </div>
  );
};

export default MediaSystem;

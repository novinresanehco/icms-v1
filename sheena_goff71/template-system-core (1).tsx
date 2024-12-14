import React, { useState, useEffect } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const TemplateSystem = () => {
  const [templates, setTemplates] = useState(new Map());
  const [securityContext, setSecurityContext] = useState(null);

  return (
    <div className="w-full">
      <ContentDisplay />
      <MediaIntegration />
      <ComponentRenderer />
    </div>
  );
};

const ContentDisplay = ({ content }) => {
  return (
    <div className="w-full max-w-6xl mx-auto bg-white shadow-sm">
      <div className="p-6 prose max-w-none">
        {content ? (
          <>
            <h1 className="text-2xl font-bold">{content.title}</h1>
            <div dangerouslySetInnerHTML={{ __html: content.body }} />
          </>
        ) : (
          <Alert>
            <AlertDescription>Loading content...</AlertDescription>
          </Alert>
        )}
      </div>
    </div>
  );
};

const MediaIntegration = ({ items = [] }) => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 p-4">
      {items.map((item, index) => (
        <div key={index} className="relative bg-gray-100 rounded-lg overflow-hidden">
          <img 
            src={item.url}
            alt={item.title}
            className="w-full h-64 object-cover"
          />
          <div className="absolute bottom-0 w-full p-2 bg-black/50">
            <p className="text-white text-sm">{item.title}</p>
          </div>
        </div>
      ))}
    </div>
  );
};

const ComponentRenderer = ({ template, data }) => {
  return (
    <div className="component-wrapper">
      {template && (
        <div dangerouslySetInnerHTML={{ __html: template }} />
      )}
    </div>
  );
};

export default TemplateSystem;

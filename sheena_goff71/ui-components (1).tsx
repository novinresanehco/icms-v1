import React, { useState, useEffect } from 'react';
import { AlertCircle, Image as ImageIcon, FileText, Check } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const ContentDisplay = ({ content, layout = 'default' }) => {
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    if (content) setIsLoading(false);
  }, [content]);

  if (isLoading) {
    return <Alert><AlertDescription>Loading content...</AlertDescription></Alert>;
  }

  return (
    <div className="w-full max-w-6xl mx-auto">
      <div className="p-4 bg-white rounded-lg shadow-sm">
        <h1 className="text-2xl font-bold mb-4">{content.title}</h1>
        <div className="prose max-w-none" dangerouslySetInnerHTML={{ __html: content.body }} />
      </div>
    </div>
  );
};

const MediaGallery = ({ items = [] }) => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-4">
      {items.map((item, index) => (
        <div key={index} className="relative overflow-hidden rounded-lg bg-gray-100">
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

const RenderTemplate = ({ template, data }) => {
  const [error, setError] = useState(null);

  useEffect(() => {
    validateTemplate(template, data).catch(setError);
  }, [template, data]);

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertCircle className="h-4 w-4" />
        <AlertDescription>{error.message}</AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="relative">
      <div dangerouslySetInnerHTML={{ __html: template }} />
    </div>
  );
};

const ComponentLibrary = {
  ContentDisplay,
  MediaGallery,
  RenderTemplate
};

export default ComponentLibrary;

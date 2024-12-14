import React, { useState, useEffect } from 'react';
import { AlertCircle, Image, FileText } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const ContentDisplay = ({ content, metadata, securityContext }) => {
  const [validatedContent, setValidatedContent] = useState(null);

  useEffect(() => {
    validateContent(content, securityContext).then(setValidatedContent);
  }, [content, securityContext]);

  return (
    <div className="w-full max-w-6xl mx-auto bg-white shadow-sm rounded-lg">
      {validatedContent ? (
        <div className="p-6">
          <h1 className="text-2xl font-bold mb-4">{validatedContent.title}</h1>
          <div className="prose max-w-none" 
               dangerouslySetInnerHTML={{ __html: validatedContent.body }} />
          {metadata && (
            <div className="mt-4 text-sm text-gray-600">
              {Object.entries(metadata).map(([key, value]) => (
                <div key={key}>{key}: {value}</div>
              ))}
            </div>
          )}
        </div>
      ) : (
        <Alert><AlertDescription>Validating content...</AlertDescription></Alert>
      )}
    </div>
  );
};

const MediaGallery = ({ items, layout = 'grid' }) => {
  const [validatedItems, setValidatedItems] = useState([]);

  useEffect(() => {
    validateMediaItems(items).then(setValidatedItems);
  }, [items]);

  return (
    <div className={`grid ${layout === 'grid' ? 'grid-cols-3' : 'grid-cols-1'} gap-4 p-4`}>
      {validatedItems.map((item, index) => (
        <div key={index} className="relative bg-gray-100 rounded-lg overflow-hidden">
          <img src={item.url} 
               alt={item.alt} 
               className="w-full h-64 object-cover"
               loading="lazy" />
          <div className="absolute bottom-0 w-full p-2 bg-black/50">
            <p className="text-white text-sm truncate">{item.title}</p>
          </div>
        </div>
      ))}
    </div>
  );
};

const TemplateRenderer = ({ template, data, type }) => {
  const [renderedContent, setRenderedContent] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    renderTemplate(template, data, type)
      .then(setRenderedContent)
      .catch(setError);
  }, [template, data, type]);

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertCircle className="h-4 w-4" />
        <AlertDescription>Template rendering failed</AlertDescription>
      </Alert>
    );
  }

  return renderedContent ? (
    <div className="template-content" 
         dangerouslySetInnerHTML={{ __html: renderedContent }} />
  ) : (
    <div className="animate-pulse bg-gray-200 h-32 rounded-lg" />
  );
};

const ComponentRegistry = {
  ContentDisplay,
  MediaGallery,
  TemplateRenderer
};

export default ComponentRegistry;

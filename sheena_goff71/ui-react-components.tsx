import React, { useState, useEffect } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const ContentDisplay = ({ content, securityLevel = 'high' }) => {
  const [validatedContent, setValidatedContent] = useState(null);
  const [error, setError] = useState(null);

  const validateContent = async (content) => {
    try {
      // Security validation before render
      const response = await fetch('/api/validate-content', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ content, securityLevel })
      });
      
      if (!response.ok) throw new Error('Content validation failed');
      
      return await response.json();
    } catch (e) {
      setError('Security validation failed');
      return null;
    }
  };

  useEffect(() => {
    validateContent(content).then(setValidatedContent);
  }, [content, securityLevel]);

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }

  if (!validatedContent) {
    return <div className="animate-pulse h-32 bg-gray-100 rounded-md" />;
  }

  return (
    <div className="w-full max-w-4xl mx-auto p-6 space-y-4">
      <h1 className="text-2xl font-bold">{validatedContent.title}</h1>
      <div 
        className="prose max-w-none"
        dangerouslySetInnerHTML={{ __html: validatedContent.body }} 
      />
      {validatedContent.media && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {validatedContent.media.map((item) => (
            <img 
              key={item.id}
              src={item.secureUrl}
              alt={item.alt}
              className="w-full h-64 object-cover rounded-lg"
            />
          ))}
        </div>
      )}
    </div>
  );
};

export default ContentDisplay;

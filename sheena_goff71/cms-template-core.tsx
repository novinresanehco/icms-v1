import React, { useState, useEffect } from 'react';
import { AlertCircle } from 'lucide-react';
import { Alert } from '@/components/ui/alert';

// Core template components
const TemplateRegistry = {
  // Content display templates
  article: ({ data }) => (
    <div className="p-4 bg-white rounded-lg shadow">
      <h1 className="text-2xl font-bold mb-4">{data.title}</h1>
      <div className="prose">{data.content}</div>
    </div>
  ),
  
  // Media display templates  
  gallery: ({ items }) => (
    <div className="grid grid-cols-3 gap-4">
      {items.map((item, idx) => (
        <img 
          key={idx}
          src="/api/placeholder/400/400"
          alt={item.alt}
          className="w-full h-full object-cover rounded-lg"
        />
      ))}
    </div>
  ),

  // Layout templates
  twoColumn: ({ left, right }) => (
    <div className="grid md:grid-cols-2 gap-4">
      <div>{left}</div>
      <div>{right}</div>
    </div>
  )
};

const TemplateEngine = () => {
  const [state, setState] = useState({
    template: null,
    content: null,
    error: null
  });

  const renderTemplate = (name, props) => {
    const Template = TemplateRegistry[name];
    if (!Template) {
      throw new Error(`Template not found: ${name}`);
    }
    return <Template {...props} />;
  };

  return (
    <div className="template-engine">
      {state.error ? (
        <Alert variant="destructive">
          <AlertCircle className="h-4 w-4" />
          <span>{state.error}</span>
        </Alert>
      ) : (
        state.template && renderTemplate(state.template, state.content)
      )}
    </div>
  );
};

export default TemplateEngine;

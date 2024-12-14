import React, { useState, useCallback } from 'react';
import { Alert } from '@/components/ui/alert';

const RenderEngine = {
  display: ({ content }) => (
    <div className="p-4 bg-white rounded-lg">
      <h1 className="text-2xl font-bold">{content.title}</h1>
      <div className="mt-4 prose">{content.body}</div>
    </div>
  ),

  media: ({ items }) => (
    <div className="grid grid-cols-3 gap-4">
      {items.map((item, idx) => (
        <div key={idx} className="aspect-square rounded-lg overflow-hidden">
          <img src="/api/placeholder/400/400" alt={item.alt} className="w-full h-full object-cover"/>
        </div>
      ))}
    </div>
  ),

  layout: ({ cols = 1, items }) => (
    <div className={`grid grid-cols-${cols} gap-4`}>
      {items.map((item, idx) => (
        <div key={idx}>{RenderEngine[item.type](item)}</div>
      ))}
    </div>
  )
};

const Template = ({ data }) => {
  const [error, setError] = useState(null);

  const render = useCallback((content) => {
    try {
      if (!content?.type || !RenderEngine[content.type]) {
        throw new Error('Invalid content type');
      }
      return RenderEngine[content.type](content);
    } catch (e) {
      setError(e.message);
      return <Alert variant="destructive">{e.message}</Alert>;
    }
  }, []);

  return (
    <div className="template-root w-full min-h-screen bg-gray-50">
      {error ? <Alert variant="destructive">{error}</Alert> : render(data)}
    </div>
  );
};

export default Template;

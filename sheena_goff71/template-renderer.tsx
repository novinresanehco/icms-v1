import React, { useState, useCallback } from 'react';

const TemplateRenderer = ({ templateId, data = {}, securityContext = {} }) => {
  const [error, setError] = useState(null);
  const [rendered, setRendered] = useState(null);

  const renderTemplate = useCallback(async () => {
    try {
      const response = await fetch('/api/template/render', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ templateId, data, securityContext })
      });

      if (!response.ok) throw new Error('Render failed');
      
      const result = await response.json();
      setRendered(result.content);
      setError(null);
    } catch (err) {
      setError(err.message);
      setRendered(null);
    }
  }, [templateId, data, securityContext]);

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded p-4 text-red-800">
        {error}
      </div>
    );
  }

  return (
    <div className="template-container">
      <div className="template-content" dangerouslySetInnerHTML={{ __html: rendered }} />
    </div>
  );
};

export default TemplateRenderer;
